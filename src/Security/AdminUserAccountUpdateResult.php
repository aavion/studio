<?php

declare(strict_types=1);

namespace App\Security;

final readonly class AdminUserAccountUpdateResult
{
    /**
     * @param array<string, mixed> $auditContext
     */
    private function __construct(
        private bool $success,
        private string $flashKey,
        private string $auditAction,
        private array $auditContext,
    ) {
    }

    /**
     * @param array<string, mixed> $auditContext
     */
    public static function success(string $flashKey, array $auditContext, string $auditAction = 'user.account_updated'): self
    {
        return new self(true, $flashKey, $auditAction, $auditContext);
    }

    /**
     * @param array<string, mixed> $auditContext
     */
    public static function error(string $flashKey, array $auditContext, string $auditAction = 'user.account_update_failed'): self
    {
        return new self(false, $flashKey, $auditAction, $auditContext);
    }

    public function flashLevel(): string
    {
        return $this->success ? 'success' : 'error';
    }

    public function flashKey(): string
    {
        return $this->flashKey;
    }

    public function auditAction(): string
    {
        return $this->auditAction;
    }

    /**
     * @return array<string, mixed>
     */
    public function auditContext(): array
    {
        return $this->auditContext;
    }
}
