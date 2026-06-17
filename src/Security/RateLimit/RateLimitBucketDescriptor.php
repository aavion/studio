<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

final readonly class RateLimitBucketDescriptor
{
    public function __construct(
        private string $name,
        private string $bucketFamily,
        private int $limit,
        private int $windowSeconds,
        private string $diagnosticsLabel,
        private bool $profileScalable = true,
        private ?int $retryAfterFloorSeconds = null,
        private bool $resettable = false,
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

    public function scaled(RateLimitProfile $profile): self
    {
        if (!$this->profileScalable || RateLimitProfile::Standard === $profile || RateLimitProfile::Off === $profile) {
            return $this;
        }

        return new self(
            $this->name,
            $this->bucketFamily,
            max(1, (int) floor($this->limit * $profile->capacityMultiplier())),
            max(1, (int) ceil($this->windowSeconds * $profile->windowMultiplier())),
            $this->diagnosticsLabel,
            $this->profileScalable,
            null === $this->retryAfterFloorSeconds
                ? null
                : max(1, (int) ceil($this->retryAfterFloorSeconds * $profile->retryAfterMultiplier())),
            $this->resettable,
        );
    }
}
