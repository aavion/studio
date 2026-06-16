<?php

declare(strict_types=1);

namespace App\Security\Abuse;

final readonly class ActionCost
{
    public function __construct(
        private string $bucketFamily,
        private int $credits,
        private bool $ordinaryEnforcement = true,
    ) {
    }

    public function bucketFamily(): string
    {
        return $this->bucketFamily;
    }

    public function credits(): int
    {
        return $this->credits;
    }

    public function ordinaryEnforcement(): bool
    {
        return $this->ordinaryEnforcement;
    }
}
