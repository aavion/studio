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
    ) {
    }

    /**
     * @param list<string> $commandPrefix
     */
    public static function available(array $commandPrefix): self
    {
        return new self(true, $commandPrefix, 'ok');
    }

    public static function unavailable(string $reason): self
    {
        return new self(false, [], $reason);
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
}
