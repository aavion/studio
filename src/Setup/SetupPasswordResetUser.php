<?php

declare(strict_types=1);

namespace App\Setup;

final readonly class SetupPasswordResetUser
{
    public function __construct(
        private string $uid,
        private string $username,
        private string $email,
        private string $status,
    ) {
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * @return array{uid: string, username: string, email: string, status: string}
     */
    public function toArray(): array
    {
        return [
            'uid' => $this->uid,
            'username' => $this->username,
            'email' => $this->email,
            'status' => $this->status,
        ];
    }
}
