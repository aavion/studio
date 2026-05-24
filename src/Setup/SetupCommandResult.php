<?php

declare(strict_types=1);

namespace App\Setup;

final readonly class SetupCommandResult
{
    public function __construct(
        private int $exitCode,
        private string $output = '',
        private string $errorOutput = '',
    ) {
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function output(): string
    {
        return $this->output;
    }

    public function errorOutput(): string
    {
        return $this->errorOutput;
    }

    public function isSuccessful(): bool
    {
        return 0 === $this->exitCode;
    }
}
