<?php

declare(strict_types=1);

namespace App\Core\Validation;

use App\Core\Message\MessageException;
use App\Security\SecurityMessageKey;

final class EmailAddress
{
    public static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function isValid(string $email): bool
    {
        return false !== filter_var(self::normalize($email), FILTER_VALIDATE_EMAIL);
    }

    public static function assert(string $email, string $messageKey = SecurityMessageKey::USER_EMAIL_INVALID): string
    {
        $normalized = self::normalize($email);

        if (false === filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            throw MessageException::invalidArgument($messageKey, [
                '%email%' => $email,
            ]);
        }

        return $normalized;
    }

    private function __construct()
    {
    }
}
