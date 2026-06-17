<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

use App\Core\Access\AccessLevel;
use App\Security\Abuse\AbuseSubject;
use App\Security\Abuse\AbuseSubjectResolution;
use App\Security\Abuse\AbuseSubjectType;

final readonly class RateLimitSubjectSelector
{
    /**
     * @return list<string>
     */
    public function subjectKeys(RateLimitBucketDescriptor $descriptor, AbuseSubjectResolution $subjects): array
    {
        if ($this->usesSubmittedAccountScope($descriptor)) {
            $submittedAccount = $subjects->first(AbuseSubjectType::SubmittedAccount);
            if ($submittedAccount instanceof AbuseSubject) {
                return $this->subjectKeysFor($descriptor, array_filter([
                    $subjects->first(AbuseSubjectType::Visitor),
                    $subjects->first(AbuseSubjectType::IpBucket),
                    $submittedAccount,
                ]));
            }
        }

        $primary = $this->primarySubject($descriptor, $subjects);
        if (!$primary instanceof AbuseSubject) {
            return [];
        }

        $keys = [$this->subjectKey($descriptor, $primary)];
        $ipBucket = $subjects->first(AbuseSubjectType::IpBucket);

        if ($ipBucket instanceof AbuseSubject && $this->includeIpSecondary($descriptor, $subjects)) {
            $keys[] = $this->subjectKey($descriptor, $ipBucket);
        }

        return array_values(array_unique($keys));
    }

    public function hasOwner(AbuseSubjectResolution $subjects): bool
    {
        $user = $subjects->first(AbuseSubjectType::User);

        return $user instanceof AbuseSubject
            && (int) ($user->context()['access_level'] ?? AccessLevel::PUBLIC) >= AccessLevel::OWNER;
    }

    public function authenticatedMultiplier(RateLimitBucketDescriptor $descriptor, AbuseSubjectResolution $subjects): int
    {
        if ($this->hasOwner($subjects) || !$subjects->first(AbuseSubjectType::User) instanceof AbuseSubject) {
            return 1;
        }

        return in_array($descriptor->bucketFamily(), ['website', 'api_read', 'api_public_read'], true)
            ? RateLimitPolicyCatalogue::AUTHENTICATED_MULTIPLIER
            : 1;
    }

    private function primarySubject(RateLimitBucketDescriptor $descriptor, AbuseSubjectResolution $subjects): ?AbuseSubject
    {
        foreach ($this->preferredTypes($descriptor) as $type) {
            $subject = $subjects->first($type);
            if ($subject instanceof AbuseSubject) {
                return $subject;
            }
        }

        return $subjects->primary();
    }

    /**
     * @return list<AbuseSubjectType>
     */
    private function preferredTypes(RateLimitBucketDescriptor $descriptor): array
    {
        if ('scheduler' === $descriptor->bucketFamily()) {
            return [
                AbuseSubjectType::SchedulerCredential,
                AbuseSubjectType::IpBucket,
                AbuseSubjectType::Visitor,
            ];
        }

        if (str_starts_with($descriptor->bucketFamily(), 'api_')) {
            return [
                AbuseSubjectType::ApiKey,
                AbuseSubjectType::User,
                AbuseSubjectType::Visitor,
                AbuseSubjectType::IpBucket,
            ];
        }

        return [
            AbuseSubjectType::User,
            AbuseSubjectType::Visitor,
            AbuseSubjectType::ApiKey,
            AbuseSubjectType::ApiKeyPrefix,
            AbuseSubjectType::IpBucket,
        ];
    }

    private function includeIpSecondary(RateLimitBucketDescriptor $descriptor, AbuseSubjectResolution $subjects): bool
    {
        if ('scheduler' === $descriptor->bucketFamily()) {
            return true;
        }

        if ($subjects->first(AbuseSubjectType::User) instanceof AbuseSubject || $subjects->first(AbuseSubjectType::ApiKey) instanceof AbuseSubject) {
            return false;
        }

        return in_array($descriptor->bucketFamily(), [
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
            'scheduler',
        ], true);
    }

    private function usesSubmittedAccountScope(RateLimitBucketDescriptor $descriptor): bool
    {
        return in_array($descriptor->bucketFamily(), [
            'login',
            'recovery_login',
            'registration',
            'password_reset',
        ], true);
    }

    public function subjectKey(RateLimitBucketDescriptor $descriptor, AbuseSubject $subject): string
    {
        return $descriptor->name().':'.$subject->type()->value.':'.$subject->identifier();
    }

    /**
     * @param iterable<AbuseSubject> $subjects
     *
     * @return list<string>
     */
    private function subjectKeysFor(RateLimitBucketDescriptor $descriptor, iterable $subjects): array
    {
        $keys = [];
        foreach ($subjects as $subject) {
            $keys[] = $this->subjectKey($descriptor, $subject);
        }

        return array_values(array_unique($keys));
    }
}
