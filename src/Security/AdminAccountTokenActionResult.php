<?php

declare(strict_types=1);

namespace App\Security;

final readonly class AdminAccountTokenActionResult
{
    private function __construct(
        private bool $success,
        private string $flashKey,
    ) {
    }

    public static function success(string $flashKey): self
    {
        return new self(true, $flashKey);
    }

    public static function error(string $flashKey): self
    {
        return new self(false, $flashKey);
    }

    public function successLevel(): string
    {
        return $this->success ? 'success' : 'error';
    }

    public function flashKey(): string
    {
        return $this->flashKey;
    }
}
