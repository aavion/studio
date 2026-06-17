<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

use App\Core\Config\Config;
use App\Security\Abuse\AbuseRequestInspector;
use App\Security\Abuse\AbuseSubject;
use App\Security\Abuse\AbuseSubjectType;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class RateLimitResetService
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

    public function resetLoginAttempts(Request $request): bool
    {
        $descriptor = $this->catalogue->descriptor('login.failure');
        if (!$descriptor instanceof RateLimitBucketDescriptor || !$descriptor->resettable() || !$this->resetStorageEnabled()) {
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
        if (!$verified || '' === $provider || 'none' === strtolower($provider) || !$this->resetStorageEnabled()) {
            return false;
        }

        $descriptor = $this->catalogue->descriptor('captcha.failure');
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

    private function resetStorageEnabled(): bool
    {
        return RateLimitProfile::fromMixed($this->config->get(RateLimitPolicyCatalogue::MODE_KEY, RateLimitProfile::Standard->value))
            ->consumesLimiterStorage();
    }

    private function reset(RateLimitBucketDescriptor $descriptor, string $subjectKey): bool
    {
        try {
            $this->limiters->reset($descriptor, $subjectKey);

            return true;
        } catch (\Throwable $exception) {
            $this->logger->warning('security.rate_limiter.reset_degraded', [
                'bucket' => $descriptor->diagnosticsLabel(),
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }
}
