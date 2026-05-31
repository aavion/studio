<?php

declare(strict_types=1);

namespace App\Security;

final readonly class PasswordPolicy
{
    public const MIN_LENGTH = 8;
    public const MIN_CHARACTER_CLASSES = 3;

    public const VIOLATION_LENGTH = 'length';
    public const VIOLATION_COMPLEXITY = 'complexity';
    public const VIOLATION_REPEATED = 'repeated';
    public const VIOLATION_PERSONAL = 'personal';

    /**
     * @return list<string>
     */
    public function violationCodes(string $password, ?string $username = null, ?string $email = null): array
    {
        $violations = [];

        if (self::MIN_LENGTH > mb_strlen($password)) {
            $violations[] = self::VIOLATION_LENGTH;
        }

        if (self::MIN_CHARACTER_CLASSES > $this->characterClassCount($password)) {
            $violations[] = self::VIOLATION_COMPLEXITY;
        }

        if (1 === preg_match('/(.)\1{3,}/u', $password)) {
            $violations[] = self::VIOLATION_REPEATED;
        }

        if ($this->containsPersonalIdentifier($password, $username, $email)) {
            $violations[] = self::VIOLATION_PERSONAL;
        }

        return $violations;
    }

    public function isValid(string $password, ?string $username = null, ?string $email = null): bool
    {
        return [] === $this->violationCodes($password, $username, $email);
    }

    public function characterClassCount(string $password): int
    {
        $classes = 0;

        foreach (['/[a-z]/', '/[A-Z]/', '/[0-9]/', '/[^a-zA-Z0-9]/'] as $pattern) {
            if (1 === preg_match($pattern, $password)) {
                ++$classes;
            }
        }

        return $classes;
    }

    private function containsPersonalIdentifier(string $password, ?string $username, ?string $email): bool
    {
        $normalizedPassword = mb_strtolower($password);
        $identifiers = array_filter([
            $username,
            is_string($email) ? strstr($email, '@', true) ?: $email : null,
        ], static fn (?string $value): bool => is_string($value) && 3 <= mb_strlen(trim($value)));

        foreach ($identifiers as $identifier) {
            if (str_contains($normalizedPassword, mb_strtolower(trim($identifier)))) {
                return true;
            }
        }

        return false;
    }
}
