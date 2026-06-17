<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

final readonly class RateLimitPolicyCatalogue
{
    public const MODE_KEY = 'security.rate_limit.mode';
    public const AUTHENTICATED_MULTIPLIER = 2;

    /**
     * @return list<RateLimitBucketDescriptor>
     */
    public function descriptors(RateLimitProfile $profile = RateLimitProfile::Standard): array
    {
        return array_map(
            static fn (RateLimitBucketDescriptor $descriptor): RateLimitBucketDescriptor => $descriptor->scaled($profile),
            $this->standardDescriptors(),
        );
    }

    /**
     * @return list<RateLimitBucketDescriptor>
     */
    public function descriptorsForFamily(string $bucketFamily, RateLimitProfile $profile = RateLimitProfile::Standard): array
    {
        return array_values(array_filter(
            $this->descriptors($profile),
            static fn (RateLimitBucketDescriptor $descriptor): bool => $descriptor->bucketFamily() === $bucketFamily,
        ));
    }

    public function descriptor(string $name, RateLimitProfile $profile = RateLimitProfile::Standard): ?RateLimitBucketDescriptor
    {
        foreach ($this->descriptors($profile) as $descriptor) {
            if ($descriptor->name() === $name) {
                return $descriptor;
            }
        }

        return null;
    }

    /**
     * @return list<RateLimitBucketDescriptor>
     */
    private function standardDescriptors(): array
    {
        return [
            $this->bucket('login.failure', 'login', 5, 900, 'security.rate.login', resettable: true),
            $this->bucket('recovery.login.minute', 'recovery_login', 2, 60, 'security.rate.recovery_login', false, 1800),
            $this->bucket('recovery.login.hour', 'recovery_login', 10, 3600, 'security.rate.recovery_login', false, 1800),
            $this->bucket('registration.hour', 'registration', 15, 3600, 'security.rate.registration'),
            $this->bucket('registration.day', 'registration', 50, 86400, 'security.rate.registration'),
            $this->bucket('password_reset.hour', 'password_reset', 9, 3600, 'security.rate.password_reset'),
            $this->bucket('password_reset.day', 'password_reset', 30, 86400, 'security.rate.password_reset'),
            $this->bucket('captcha.failure', 'captcha_failure', 5, 600, 'security.rate.captcha_failure', resettable: true),
            $this->bucket('website.deliberate.burst', 'website', 30, 60, 'security.rate.website_burst'),
            $this->bucket('website.deliberate.sustained', 'website', 300, 1800, 'security.rate.website_sustained'),
            $this->bucket('website.form', 'website_form', 10, 600, 'security.rate.website_form'),
            $this->bucket('website.prefetch.minute', 'website_prefetch', 120, 60, 'security.rate.prefetch_observation'),
            $this->bucket('website.prefetch.sustained', 'website_prefetch', 600, 1800, 'security.rate.prefetch_observation'),
            $this->bucket('api.read', 'api_read', 600, 60, 'security.rate.api_read'),
            $this->bucket('api.public_read', 'api_public_read', 120, 60, 'security.rate.api_public_read'),
            $this->bucket('api.write', 'api_write', 300, 60, 'security.rate.api_write'),
            $this->bucket('scheduler.minute', 'scheduler', 5, 60, 'security.rate.scheduler'),
            $this->bucket('scheduler.hour', 'scheduler', 60, 3600, 'security.rate.scheduler'),
            $this->bucket('setup.apply', 'setup_apply', 40, 900, 'security.rate.setup_apply'),
            $this->bucket('admin.mutation', 'admin_mutation', 240, 300, 'security.rate.admin_mutation'),
            $this->bucket('upload_archive.validation', 'upload_archive', 160, 600, 'security.rate.upload_archive'),
            $this->bucket('download_diagnostics', 'download_diagnostics', 120, 600, 'security.rate.download_diagnostics'),
            $this->bucket('suspicious.probe', 'suspicious_probe', 1, 600, 'security.rate.suspicious_probe', false),
        ];
    }

    private function bucket(
        string $name,
        string $family,
        int $limit,
        int $windowSeconds,
        string $diagnosticsLabel,
        bool $profileScalable = true,
        ?int $retryAfterFloorSeconds = null,
        bool $resettable = false,
    ): RateLimitBucketDescriptor {
        return new RateLimitBucketDescriptor(
            $name,
            $family,
            $limit,
            $windowSeconds,
            $diagnosticsLabel,
            $profileScalable,
            $retryAfterFloorSeconds,
            $resettable,
        );
    }
}
