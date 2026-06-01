<?php

declare(strict_types=1);

namespace App\Setup;

use App\Security\PasswordPolicy;

final readonly class SetupPasswordPolicy
{
    public const MIN_ADMIN_PASSWORD_LENGTH = PasswordPolicy::MIN_LENGTH;

    public function __construct(
        private PasswordPolicy $passwordPolicy = new PasswordPolicy(),
    ) {
    }

    public function isValidAdminPassword(string $password, ?string $username = null, ?string $email = null): bool
    {
        return $this->passwordPolicy->isValid($password, $username, $email);
    }

    /**
     * @return list<string>
     */
    public function violationCodes(string $password, ?string $username = null, ?string $email = null): array
    {
        return $this->passwordPolicy->violationCodes($password, $username, $email);
    }
}
