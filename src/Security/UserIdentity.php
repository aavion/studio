<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\UserAccount;

final readonly class UserIdentity
{
    public function __construct(
        private ?string $uid,
        private string $username,
        private ?string $email,
        private string $displayName,
        private UserAccountStatus $status,
        private bool $exists,
    ) {
    }

    public static function fromUser(UserAccount $user): self
    {
        $profile = $user->profile();
        $displayName = $profile['display_name'] ?? null;

        if (!is_string($displayName) || '' === trim($displayName)) {
            $displayName = $user->username();
        }

        return new self(
            $user->uid(),
            $user->username(),
            $user->email(),
            $displayName,
            $user->status(),
            true,
        );
    }

    public static function deleted(?string $uid = null, string $label = 'deleted user'): self
    {
        return new self(
            $uid,
            $label,
            null,
            $label,
            UserAccountStatus::Deleted,
            false,
        );
    }

    public function uid(): ?string
    {
        return $this->uid;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function status(): UserAccountStatus
    {
        return $this->status;
    }

    public function exists(): bool
    {
        return $this->exists;
    }
}
