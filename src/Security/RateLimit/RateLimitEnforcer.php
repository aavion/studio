<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

use App\Api\Http\ApiRequestContext;
use App\Api\Security\ApiRequestMethodPolicy;
use App\Core\Config\Config;
use App\Core\Message\Message;
use App\Core\Message\MessageReporterInterface;
use App\Security\Abuse\AbuseRequestInspector;
use App\Security\Abuse\AbuseRequestProfile;
use App\Security\Abuse\AbuseSubjectResolution;
use App\Security\Abuse\AbuseSubjectType;
use App\Security\Abuse\ActionCost;
use App\Security\Abuse\RequestFamily;
use App\Security\Abuse\RequestIntent;
use App\Security\ApiKeyStatus;
use App\Security\SecurityMessageCode;
use App\Security\SecurityMessageKey;
use Symfony\Component\HttpFoundation\Request;

final readonly class RateLimitEnforcer
{
    public function __construct(
        private AbuseRequestInspector $inspector,
        private Config $config,
        private RateLimitPolicyCatalogue $catalogue,
        private RateLimitSubjectSelector $subjects,
        private RateLimitLimiterFactory $limiters,
        private MessageReporterInterface $messages,
        private ApiRequestMethodPolicy $apiMethods = new ApiRequestMethodPolicy(),
    ) {
    }

    public function check(Request $request, RateLimitEnforcementStage $stage = RateLimitEnforcementStage::All): RateLimitCheckResult
    {
        $inspection = $this->inspector->inspect($request);
        $profile = $inspection['profile'];
        $subjectResolution = $inspection['subjects'];
        $cost = $inspection['cost'];
        $mode = RateLimitProfile::fromMixed($this->config->get(RateLimitPolicyCatalogue::MODE_KEY, RateLimitProfile::Standard->value));

        if ($profile->suspiciousProbe()) {
            if (!in_array($stage, [RateLimitEnforcementStage::All, RateLimitEnforcementStage::SuspiciousProbe], true)) {
                return RateLimitCheckResult::allow();
            }

            return $this->checkSuspiciousProbe($profile, $subjectResolution, $cost, $mode);
        }

        if (!$mode->consumesLimiterStorage() || !$cost->ordinaryEnforcement() || $this->isOwnerExempt($request, $profile, $subjectResolution, $cost, $stage)) {
            return RateLimitCheckResult::allow();
        }

        return $this->consume($profile, $subjectResolution, $cost, $mode, $stage);
    }

    private function checkSuspiciousProbe(AbuseRequestProfile $profile, AbuseSubjectResolution $subjects, ActionCost $cost, RateLimitProfile $mode): RateLimitCheckResult
    {
        if (!$mode->consumesLimiterStorage()) {
            return RateLimitCheckResult::blockSuspiciousProbe();
        }

        $result = $this->consume($profile, $subjects, $cost, $mode, RateLimitEnforcementStage::SuspiciousProbe);

        return RateLimitCheckResult::blockSuspiciousProbe($result->storageDegraded());
    }

    private function consume(AbuseRequestProfile $profile, AbuseSubjectResolution $subjects, ActionCost $cost, RateLimitProfile $mode, RateLimitEnforcementStage $stage): RateLimitCheckResult
    {
        try {
            $plannedConsumes = [];
            $credits = max(1, $cost->credits());

            foreach ($this->descriptors($profile, $subjects, $cost, $mode, $stage) as $descriptor) {
                $descriptor = $descriptor->withCapacityMultiplier($this->subjects->authenticatedMultiplier($descriptor, $subjects));

                foreach ($this->subjects->subjectKeys($descriptor, $subjects) as $subjectKey) {
                    $plannedConsumes[] = [$descriptor, $subjectKey, $credits];
                }
            }

            foreach ($plannedConsumes as [$descriptor, $subjectKey, $credits]) {
                $retryAfter = $this->limiters->accepts($descriptor, $subjectKey, $credits);
                if ($retryAfter instanceof \DateTimeImmutable) {
                    return RateLimitCheckResult::reject($this->retryAfterSeconds($descriptor, $retryAfter), $descriptor->diagnosticsLabel());
                }
            }

            foreach ($plannedConsumes as [$descriptor, $subjectKey, $credits]) {
                $retryAfter = $this->limiters->consume($descriptor, $subjectKey, $credits);
                if ($retryAfter instanceof \DateTimeImmutable) {
                    return RateLimitCheckResult::reject($this->retryAfterSeconds($descriptor, $retryAfter), $descriptor->diagnosticsLabel());
                }
            }
        } catch (\Throwable $exception) {
            $this->reportDegradedConsume($profile, $mode, $exception);

            return RateLimitCheckResult::allow(storageDegraded: true);
        }

        return RateLimitCheckResult::allow();
    }

    /**
     * @return list<RateLimitBucketDescriptor>
     */
    private function descriptors(AbuseRequestProfile $profile, AbuseSubjectResolution $subjects, ActionCost $cost, RateLimitProfile $mode, RateLimitEnforcementStage $stage): array
    {
        $primaryFamily = $this->bucketFamily($cost, $subjects);
        $families = [];
        $primaryDescriptors = $this->descriptorsForFamily($primaryFamily, $mode, $stage);

        if ($stage->consumesWebsiteFamily() && $this->shouldConsumeWebsiteFamily($profile, $primaryFamily)) {
            $families[] = 'website';
        }

        $descriptors = $primaryDescriptors;
        foreach (array_values(array_unique($families)) as $family) {
            array_push($descriptors, ...$this->descriptorsForFamily($family, $mode, $stage));
        }

        return $descriptors;
    }

    /**
     * @return list<RateLimitBucketDescriptor>
     */
    private function descriptorsForFamily(string $family, RateLimitProfile $mode, RateLimitEnforcementStage $stage): array
    {
        return array_values(array_filter(
            $this->catalogue->descriptorsForFamily($family, $mode),
            static fn (RateLimitBucketDescriptor $descriptor): bool => $descriptor->handlesStage($stage),
        ));
    }

    private function bucketFamily(ActionCost $cost, AbuseSubjectResolution $subjects): string
    {
        if ('api_read' === $cost->bucketFamily() && !$subjects->first(AbuseSubjectType::ApiKey)) {
            return 'api_public_read';
        }

        return $cost->bucketFamily();
    }

    private function shouldConsumeWebsiteFamily(AbuseRequestProfile $profile, string $bucketFamily): bool
    {
        if (!in_array($profile->family(), [RequestFamily::Browser, RequestFamily::Admin, RequestFamily::Editor], true)) {
            return false;
        }

        if (RequestIntent::TurboPrefetch === $profile->intent()) {
            return false;
        }

        return !in_array($bucketFamily, ['website', 'website_prefetch', 'recovery_login'], true);
    }

    private function retryAfterSeconds(RateLimitBucketDescriptor $descriptor, \DateTimeImmutable $retryAfter): int
    {
        $seconds = max(1, $retryAfter->getTimestamp() - time());
        $floor = $descriptor->retryAfterFloorSeconds();

        return null === $floor ? $seconds : max($seconds, $floor);
    }

    private function isOwnerExempt(Request $request, AbuseRequestProfile $profile, AbuseSubjectResolution $subjects, ActionCost $cost, RateLimitEnforcementStage $stage): bool
    {
        if (RateLimitEnforcementStage::AuthenticationFailure === $stage) {
            return false;
        }

        if (RequestFamily::Scheduler === $profile->family() || 'scheduler' === $cost->bucketFamily()) {
            return false;
        }

        if (RequestFamily::Api === $profile->family() && !$this->apiMethods->isSafeEffectiveMethod($request) && $this->readOnlyApiKey($request)) {
            return false;
        }

        return $this->subjects->hasOwner($subjects);
    }

    private function readOnlyApiKey(Request $request): bool
    {
        return ApiKeyStatus::ReadOnly === ApiRequestContext::fromRequest($request)?->apiKeyStatus();
    }

    private function reportDegradedConsume(AbuseRequestProfile $profile, RateLimitProfile $mode, \Throwable $exception): void
    {
        $context = [
            'profile' => $mode->value,
            'intent' => $profile->intent()->value,
            'family' => $profile->family()->value,
            'exception_class' => $exception::class,
        ];

        $this->messages->report(
            Message::warning(SecurityMessageCode::RATE_LIMIT_STORAGE_DEGRADED, SecurityMessageKey::RATE_LIMIT_STORAGE_DEGRADED, context: $context),
            ['operation' => 'security.rate_limit.consume'],
        );
    }
}
