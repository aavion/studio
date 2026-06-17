<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

use App\Core\Config\Config;
use App\Core\Message\Message;
use App\Core\Message\MessageReporterInterface;
use App\Security\Abuse\AbuseRequestInspector;
use App\Security\Abuse\AbuseSubject;
use App\Security\Abuse\AbuseSubjectType;
use App\Security\SecurityMessageCode;
use App\Security\SecurityMessageKey;
use Symfony\Component\HttpFoundation\Request;

final readonly class RateLimitResetService
{
    public function __construct(
        private AbuseRequestInspector $inspector,
        private Config $config,
        private RateLimitPolicyCatalogue $catalogue,
        private RateLimitSubjectSelector $subjects,
        private RateLimitLimiterFactory $limiters,
        private MessageReporterInterface $messages,
    ) {
    }

    public function resetLoginAttempts(Request $request): bool
    {
        $profile = $this->profile();
        $descriptor = $this->catalogue->descriptor('login.failure', $profile);
        if (!$descriptor instanceof RateLimitBucketDescriptor || !$descriptor->resettable() || !$profile->consumesLimiterStorage()) {
            return false;
        }

        $subjectResolution = $this->inspector->inspect($request)['subjects'];
        $reset = false;

        foreach ([AbuseSubjectType::Visitor, AbuseSubjectType::IpBucket] as $type) {
            $subject = $subjectResolution->first($type);
            if (!$subject instanceof AbuseSubject) {
                continue;
            }

            $reset = $this->reset($descriptor, $this->subjects->subjectKey($descriptor, $subject)) || $reset;
        }

        return $reset;
    }

    public function resetVerifiedCaptchaFailure(Request $request, ?string $provider, bool $verified): bool
    {
        $provider = is_string($provider) ? trim($provider) : '';
        $profile = $this->profile();
        if (!$verified || '' === $provider || 'none' === strtolower($provider) || !$profile->consumesLimiterStorage()) {
            return false;
        }

        $descriptor = $this->catalogue->descriptor('captcha.failure', $profile);
        if (!$descriptor instanceof RateLimitBucketDescriptor || !$descriptor->resettable()) {
            return false;
        }

        $subjectResolution = $this->inspector->inspect($request)['subjects'];
        $reset = false;

        foreach ($this->subjects->subjectKeys($descriptor, $subjectResolution) as $subjectKey) {
            $reset = $this->reset($descriptor, $subjectKey) || $reset;
        }

        return $reset;
    }

    private function profile(): RateLimitProfile
    {
        return RateLimitProfile::fromMixed($this->config->get(RateLimitPolicyCatalogue::MODE_KEY, RateLimitProfile::Standard->value));
    }

    private function reset(RateLimitBucketDescriptor $descriptor, string $subjectKey): bool
    {
        try {
            $this->limiters->reset($descriptor, $subjectKey);

            return true;
        } catch (\Throwable $exception) {
            $this->reportDegradedReset($descriptor, $exception);

            return false;
        }
    }

    private function reportDegradedReset(RateLimitBucketDescriptor $descriptor, \Throwable $exception): void
    {
        $context = [
            'bucket' => $descriptor->diagnosticsLabel(),
            'exception_class' => $exception::class,
        ];

        $this->messages->report(
            Message::warning(SecurityMessageCode::RATE_LIMIT_RESET_DEGRADED, SecurityMessageKey::RATE_LIMIT_RESET_DEGRADED, context: $context),
            ['operation' => 'security.rate_limit.reset'],
        );
    }
}
