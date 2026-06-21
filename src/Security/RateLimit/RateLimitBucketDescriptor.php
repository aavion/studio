<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

final readonly class RateLimitBucketDescriptor
{
    /**
     * @param list<RateLimitEnforcementStage> $stages
     */
    public function __construct(
        private string $name,
        private string $bucketFamily,
        private int $limit,
        private int $windowSeconds,
        private string $diagnosticsLabel,
        private bool $profileScalable = true,
        private ?int $retryAfterFloorSeconds = null,
        private bool $resettable = false,
        private int $minimumLimit = 1,
        private ?RateLimitSubjectPolicy $subjectPolicy = null,
        private array $stages = [RateLimitEnforcementStage::All],
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function bucketFamily(): string
    {
        return $this->bucketFamily;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function windowSeconds(): int
    {
        return $this->windowSeconds;
    }

    public function diagnosticsLabel(): string
    {
        return $this->diagnosticsLabel;
    }

    public function retryAfterFloorSeconds(): ?int
    {
        return $this->retryAfterFloorSeconds;
    }

    public function resettable(): bool
    {
        return $this->resettable;
    }

    public function minimumLimit(): int
    {
        return $this->minimumLimit;
    }

    public function subjectPolicy(): RateLimitSubjectPolicy
    {
        return $this->subjectPolicy ?? new RateLimitSubjectPolicy([]);
    }

    public function handlesStage(RateLimitEnforcementStage $stage): bool
    {
        return RateLimitEnforcementStage::All === $stage
            || in_array(RateLimitEnforcementStage::All, $this->stages, true)
            || in_array($stage, $this->stages, true);
    }

    public function scaled(RateLimitProfile $profile): self
    {
        if (!$this->profileScalable || RateLimitProfile::Standard === $profile || RateLimitProfile::Off === $profile) {
            return $this;
        }

        return new self(
            $this->name,
            $this->bucketFamily,
            max($this->minimumLimit, (int) floor($this->limit * $profile->capacityMultiplier())),
            max(1, (int) ceil($this->windowSeconds * $profile->windowMultiplier())),
            $this->diagnosticsLabel,
            $this->profileScalable,
            null === $this->retryAfterFloorSeconds
                ? null
                : max(1, (int) ceil($this->retryAfterFloorSeconds * $profile->retryAfterMultiplier())),
            $this->resettable,
            $this->minimumLimit,
            $this->subjectPolicy,
            $this->stages,
        );
    }

    public function withWindowSeconds(int $windowSeconds): self
    {
        return new self(
            $this->name,
            $this->bucketFamily,
            $this->limit,
            max(1, $windowSeconds),
            $this->diagnosticsLabel,
            $this->profileScalable,
            $this->retryAfterFloorSeconds,
            $this->resettable,
            $this->minimumLimit,
            $this->subjectPolicy,
            $this->stages,
        );
    }

    public function withCapacityMultiplier(int $multiplier): self
    {
        if ($multiplier <= 1) {
            return $this;
        }

        return new self(
            $this->name,
            $this->bucketFamily,
            $this->limit * $multiplier,
            $this->windowSeconds,
            $this->diagnosticsLabel,
            $this->profileScalable,
            $this->retryAfterFloorSeconds,
            $this->resettable,
            $this->minimumLimit * $multiplier,
            $this->subjectPolicy,
            $this->stages,
        );
    }
}
