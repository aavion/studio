<?php

declare(strict_types=1);

namespace App\Core\Process;

final readonly class PhpCliBinaryValidationResult
{
    /**
     * @param array<string, mixed> $context
     */
    private function __construct(
        private bool $valid,
        private string $reason,
        private array $context = [],
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function valid(array $context = []): self
    {
        return new self(true, 'ok', $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function invalid(string $reason, array $context = []): self
    {
        return new self(false, $reason, $context);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
