<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

use App\Security\Abuse\AbuseSubjectType;

final readonly class RateLimitSubjectPolicy
{
    /**
     * @param list<AbuseSubjectType> $preferredTypes
     */
    public function __construct(
        private array $preferredTypes,
        private bool $submittedAccountScope = false,
        private bool $ipSecondary = false,
        private bool $ipSecondaryWithAuthenticatedSubject = false,
        private bool $authenticatedMultiplier = false,
    ) {
    }

    /**
     * @return list<AbuseSubjectType>
     */
    public function preferredTypes(): array
    {
        return $this->preferredTypes;
    }

    public function submittedAccountScope(): bool
    {
        return $this->submittedAccountScope;
    }

    public function ipSecondary(): bool
    {
        return $this->ipSecondary;
    }

    public function ipSecondaryWithAuthenticatedSubject(): bool
    {
        return $this->ipSecondaryWithAuthenticatedSubject;
    }

    public function authenticatedMultiplier(): bool
    {
        return $this->authenticatedMultiplier;
    }
}
