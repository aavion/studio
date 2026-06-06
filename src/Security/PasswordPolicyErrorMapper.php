<?php

declare(strict_types=1);

namespace App\Security;

final readonly class PasswordPolicyErrorMapper
{
    public function __construct(private PasswordPolicy $passwordPolicy)
    {
    }

    /**
     * @return list<string>
     */
    public function errorKeys(string $password, string $username, string $email): array
    {
        return array_map(
            static fn (string $violation): string => match ($violation) {
                PasswordPolicy::VIOLATION_COMPLEXITY => 'ui.user.password.errors.new_password_complexity',
                PasswordPolicy::VIOLATION_REPEATED => 'ui.user.password.errors.new_password_repeated',
                PasswordPolicy::VIOLATION_PERSONAL => 'ui.user.password.errors.new_password_personal',
                default => 'ui.user.password.errors.new_password_length',
            },
            $this->passwordPolicy->violationCodes($password, $username, $email),
        );
    }
}
