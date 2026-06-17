<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

use App\Core\Config\Config;
use App\Security\Abuse\AbuseRequestInspector;
use App\Security\Abuse\AbuseRequestProfile;
use App\Security\Abuse\AbuseSubjectResolution;
use App\Security\Abuse\AbuseSubjectType;
use App\Security\Abuse\ActionCost;
use App\Security\Abuse\RequestFamily;
use App\Security\Abuse\RequestIntent;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class RateLimitEnforcer
{
    public function __construct(
        private AbuseRequestInspector $inspector,
        private Config $config,
        private RateLimitPolicyCatalogue $catalogue,
        private RateLimitSubjectSelector $subjects,
        private RateLimitLimiterFactory $limiters,
        private LoggerInterface $logger,
    ) {
    }

    public function check(Request $request): RateLimitCheckResult
    {
        $inspection = $this->inspector->inspect($request);
        $profile = $inspection['profile'];
        $subjectResolution = $inspection['subjects'];
        $cost = $inspection['cost'];
        $mode = RateLimitProfile::fromMixed($this->config->get(RateLimitPolicyCatalogue::MODE_KEY, RateLimitProfile::Standard->value));

        if ($profile->suspiciousProbe()) {
            return $this->checkSuspiciousProbe($profile, $subjectResolution, $mode);
        }

        if (!$mode->consumesLimiterStorage() || !$cost->ordinaryEnforcement() || $this->subjects->hasOwner($subjectResolution)) {
            return RateLimitCheckResult::allow();
        }

        return $this->consume($profile, $subjectResolution, $cost, $mode);
    }

    private function checkSuspiciousProbe(AbuseRequestProfile $profile, AbuseSubjectResolution $subjects, RateLimitProfile $mode): RateLimitCheckResult
    {
        if (!$mode->consumesLimiterStorage()) {
            return RateLimitCheckResult::blockSuspiciousProbe();
        }

        $result = $this->consume($profile, $subjects, new ActionCost('suspicious_probe', 1), $mode);

        return RateLimitCheckResult::blockSuspiciousProbe($result->storageDegraded());
    }

    private function consume(AbuseRequestProfile $profile, AbuseSubjectResolution $subjects, ActionCost $cost, RateLimitProfile $mode): RateLimitCheckResult
    {
        try {
            foreach ($this->descriptors($profile, $subjects, $cost, $mode) as $descriptor) {
                $descriptor = $descriptor->withCapacityMultiplier($this->subjects->authenticatedMultiplier($descriptor, $subjects));

                foreach ($this->subjects->subjectKeys($descriptor, $subjects) as $subjectKey) {
                    $retryAfter = $this->limiters->consume($descriptor, $subjectKey, max(1, $cost->credits()));
                    if ($retryAfter instanceof \DateTimeImmutable) {
                        return RateLimitCheckResult::reject($this->retryAfterSeconds($descriptor, $retryAfter), $descriptor->diagnosticsLabel());
                    }
                }
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('security.rate_limiter.degraded', [
                'profile' => $mode->value,
                'intent' => $profile->intent()->value,
                'family' => $profile->family()->value,
                'exception_class' => $exception::class,
            ]);

            return RateLimitCheckResult::allow(storageDegraded: true);
        }

        return RateLimitCheckResult::allow();
    }

    /**
     * @return list<RateLimitBucketDescriptor>
     */
    private function descriptors(AbuseRequestProfile $profile, AbuseSubjectResolution $subjects, ActionCost $cost, RateLimitProfile $mode): array
    {
        $families = [$this->bucketFamily($cost, $subjects)];

        if ($this->shouldConsumeWebsiteFamily($profile, $families[0])) {
            $families[] = 'website';
        }

        $descriptors = [];
        foreach (array_values(array_unique($families)) as $family) {
            array_push($descriptors, ...$this->catalogue->descriptorsForFamily($family, $mode));
        }

        return $descriptors;
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

        return !in_array($bucketFamily, ['website', 'website_prefetch'], true);
    }

    private function retryAfterSeconds(RateLimitBucketDescriptor $descriptor, \DateTimeImmutable $retryAfter): int
    {
        $seconds = max(1, $retryAfter->getTimestamp() - time());
        $floor = $descriptor->retryAfterFloorSeconds();

        return null === $floor ? $seconds : max($seconds, $floor);
    }
}
