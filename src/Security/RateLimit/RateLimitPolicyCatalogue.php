<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

use App\Security\Abuse\AbuseSubjectType;
use App\Security\Abuse\ActionCostCatalogue;

final readonly class RateLimitPolicyCatalogue
{
    public const MODE_KEY = 'security.rate_limit.mode';
    public const AUTHENTICATED_MULTIPLIER = 2;
    private const MIN_ACTIONS_PER_DERIVED_PROFILE = 2;
    private const SINGLE_ACTION_FLOOR_FAMILIES = [
        'scheduler' => true,
        'suspicious_probe' => true,
    ];
    private const WEBSITE_COMPANION_FAMILIES = [
        'website_form',
        'registration',
        'password_reset',
        'admin_mutation',
        'upload_archive',
        'download_diagnostics',
    ];

    /**
     * @var array<string, int>
     */
    private array $creditCosts;

    public function __construct(?ActionCostCatalogue $actionCosts = null)
    {
        $this->creditCosts = ($actionCosts ?? new ActionCostCatalogue())->uniqueCreditsByBucketFamily();
    }

    /**
     * @return list<RateLimitBucketDescriptor>
     */
    public function descriptors(RateLimitProfile $profile = RateLimitProfile::Standard): array
    {
        return array_map(
            fn (RateLimitBucketDescriptor $descriptor): RateLimitBucketDescriptor => $this->profileDescriptor($descriptor, $profile),
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
            $this->bucket('registration.hour', 'registration', 3, 3600, 'security.rate.registration'),
            $this->bucket('registration.day', 'registration', 10, 86400, 'security.rate.registration'),
            $this->bucket('password_reset.hour', 'password_reset', 3, 3600, 'security.rate.password_reset'),
            $this->bucket('password_reset.day', 'password_reset', 10, 86400, 'security.rate.password_reset'),
            $this->bucket('captcha.failure', 'captcha_failure', 5, 600, 'security.rate.captcha_failure', resettable: true),
            $this->bucket('website.deliberate.burst', 'website', 30, 60, 'security.rate.website_burst'),
            $this->bucket('website.deliberate.sustained', 'website', 300, 1800, 'security.rate.website_sustained'),
            $this->bucket('website.form', 'website_form', 5, 600, 'security.rate.website_form'),
            $this->bucket('website.prefetch.minute', 'website_prefetch', 120, 60, 'security.rate.prefetch_observation'),
            $this->bucket('website.prefetch.sustained', 'website_prefetch', 600, 1800, 'security.rate.prefetch_observation'),
            $this->bucket('api.read', 'api_read', 600, 60, 'security.rate.api_read'),
            $this->bucket('api.public_read', 'api_public_read', 120, 60, 'security.rate.api_public_read'),
            $this->bucket('api.write', 'api_write', 60, 60, 'security.rate.api_write'),
            $this->bucket('scheduler.interval', 'scheduler', 1, 60, 'security.rate.scheduler', false),
            $this->bucket('setup.apply', 'setup_apply', 5, 900, 'security.rate.setup_apply'),
            $this->bucket('admin.mutation', 'admin_mutation', 30, 300, 'security.rate.admin_mutation'),
            $this->bucket('upload_archive.validation', 'upload_archive', 20, 600, 'security.rate.upload_archive'),
            $this->bucket('download_diagnostics', 'download_diagnostics', 30, 600, 'security.rate.download_diagnostics'),
            $this->bucket('suspicious.probe', 'suspicious_probe', 1, 600, 'security.rate.suspicious_probe'),
        ];
    }

    private function profileDescriptor(RateLimitBucketDescriptor $descriptor, RateLimitProfile $profile): RateLimitBucketDescriptor
    {
        if ('scheduler.interval' === $descriptor->name()) {
            return match ($profile) {
                RateLimitProfile::Strict => $descriptor->withWindowSeconds(900),
                RateLimitProfile::Panic => $descriptor->withWindowSeconds(3600),
                default => $descriptor,
            };
        }

        return $descriptor->scaled($profile);
    }

    private function bucket(
        string $name,
        string $family,
        int $actionLimit,
        int $windowSeconds,
        string $diagnosticsLabel,
        bool $profileScalable = true,
        ?int $retryAfterFloorSeconds = null,
        bool $resettable = false,
    ): RateLimitBucketDescriptor {
        $cost = $this->creditCostForFamily($family);
        $minimumLimit = $this->minimumLimitForFamily($family, $cost);

        return new RateLimitBucketDescriptor(
            $name,
            $family,
            max(1, $actionLimit) * $cost,
            $windowSeconds,
            $diagnosticsLabel,
            $profileScalable,
            $retryAfterFloorSeconds,
            $resettable,
            $minimumLimit,
            $this->subjectPolicyForFamily($family),
            $this->stagesForFamily($family),
        );
    }

    private function creditCostForFamily(string $family): int
    {
        if ('api_public_read' === $family) {
            return max(1, $this->creditCosts['api_read'] ?? 1);
        }

        return max(1, $this->creditCosts[$family] ?? 1);
    }

    private function minimumLimitForFamily(string $family, int $cost): int
    {
        $minimumCost = $cost;
        if ('website' === $family) {
            foreach (self::WEBSITE_COMPANION_FAMILIES as $companion) {
                $minimumCost = max($minimumCost, $this->creditCostForFamily($companion));
            }
        }

        $actions = isset(self::SINGLE_ACTION_FLOOR_FAMILIES[$family]) ? 1 : self::MIN_ACTIONS_PER_DERIVED_PROFILE;

        return $actions * $minimumCost;
    }

    private function subjectPolicyForFamily(string $family): RateLimitSubjectPolicy
    {
        $apiSubjects = [
            AbuseSubjectType::ApiKey,
            AbuseSubjectType::User,
            AbuseSubjectType::Visitor,
            AbuseSubjectType::IpBucket,
        ];
        $defaultSubjects = [
            AbuseSubjectType::User,
            AbuseSubjectType::Visitor,
            AbuseSubjectType::ApiKey,
            AbuseSubjectType::ApiKeyPrefix,
            AbuseSubjectType::IpBucket,
        ];

        if ('scheduler' === $family) {
            return new RateLimitSubjectPolicy(
                [AbuseSubjectType::SchedulerCredential, AbuseSubjectType::IpBucket, AbuseSubjectType::Visitor],
                ipSecondary: true,
                ipSecondaryWithAuthenticatedSubject: true,
            );
        }

        return new RateLimitSubjectPolicy(
            in_array($family, ['api_read', 'api_public_read', 'api_write', 'admin_mutation', 'upload_archive', 'download_diagnostics'], true)
                ? $apiSubjects
                : $defaultSubjects,
            submittedAccountScope: in_array($family, ['login', 'recovery_login', 'registration', 'password_reset'], true),
            ipSecondary: in_array($family, [
                'website',
                'website_form',
                'login',
                'recovery_login',
                'registration',
                'password_reset',
                'captcha_failure',
                'setup_apply',
                'suspicious_probe',
                'api_read',
                'api_write',
                'api_public_read',
                'admin_mutation',
                'upload_archive',
                'download_diagnostics',
            ], true),
            authenticatedMultiplier: in_array($family, ['website', 'api_read', 'api_public_read'], true),
        );
    }

    /**
     * @return list<RateLimitEnforcementStage>
     */
    private function stagesForFamily(string $family): array
    {
        return match ($family) {
            'suspicious_probe' => [RateLimitEnforcementStage::SuspiciousProbe],
            'login' => [RateLimitEnforcementStage::AuthenticationFailure],
            'recovery_login' => [RateLimitEnforcementStage::Ordinary],
            'api_read', 'api_public_read', 'api_write', 'admin_mutation', 'upload_archive', 'download_diagnostics' => [
                RateLimitEnforcementStage::Ordinary,
                RateLimitEnforcementStage::AuthenticationFailure,
            ],
            default => [RateLimitEnforcementStage::Ordinary],
        };
    }
}
