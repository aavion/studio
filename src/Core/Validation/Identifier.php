<?php

declare(strict_types=1);

namespace App\Core\Validation;

use App\Core\Access\AccessMessageKey;
use App\Core\Message\MessageException;

final class Identifier
{
    public static function assertSnakeCase(string $identifier, string $messageKey, string $parameterName = '%identifier%'): string
    {
        if (!IdentifierSpec::isSnakeIdentifier($identifier)) {
            throw MessageException::invalidArgument($messageKey, [
                $parameterName => $identifier,
            ]);
        }

        return $identifier;
    }

    public static function assertAclGroupIdentifier(string $identifier, string $parameterName = '%identifier%'): string
    {
        if (!IdentifierSpec::isAclGroupIdentifier($identifier)) {
            throw MessageException::invalidArgument(AccessMessageKey::ACCESS_GROUP_IDENTIFIER_INVALID, [
                $parameterName => $identifier,
            ]);
        }

        return $identifier;
    }

    public static function assertConfigKey(string $key, string $messageKey): string
    {
        if (!IdentifierSpec::isDotPathIdentifier($key)) {
            throw MessageException::invalidArgument($messageKey, [
                '%key%' => $key,
            ]);
        }

        return $key;
    }

    private function __construct()
    {
    }
}
