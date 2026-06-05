<?php

declare(strict_types=1);

namespace App\Core\Process;

final readonly class PhpCliBinaryResolution
{
    /**
     * @param list<string> $commandPrefix
     */
    private function __construct(
        private bool $available,
        private array $commandPrefix,
        private string $reason,
        private array $context = [],
    ) {
    }

    /**
     * @param list<string> $commandPrefix
     * @param array<string, mixed> $context
     */
    public static function available(array $commandPrefix, array $context = []): self
    {
        return new self(true, $commandPrefix, 'ok', $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function unavailable(string $reason, array $context = []): self
    {
        return new self(false, [], $reason, $context);
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * @return list<string>
     */
    public function commandPrefix(): array
    {
        return $this->commandPrefix;
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
