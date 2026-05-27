<?php

declare(strict_types=1);

namespace App\Setup;

final readonly class SetupPasswordPolicy
{
    public const MIN_ADMIN_PASSWORD_LENGTH = 12;

    public function isValidAdminPassword(string $password): bool
    {
        return self::MIN_ADMIN_PASSWORD_LENGTH <= mb_strlen($password);
    }
}
