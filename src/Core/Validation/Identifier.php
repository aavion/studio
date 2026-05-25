<?php

declare(strict_types=1);

namespace App\Core\Validation;

use App\Core\Message\MessageCode;
use App\Core\Message\MessageException;

final class Identifier
{
    public static function assertSnakeCase(string $identifier, string $messageKey, string $parameterName = '%identifier%'): string
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $identifier)) {
            throw MessageException::forMessage(MessageCode::E_INVALID_ARGUMENT, $messageKey, [
                $parameterName => $identifier,
            ]);
        }

        return $identifier;
    }

    public static function assertConfigKey(string $key, string $messageKey): string
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $key)) {
            throw MessageException::forMessage(MessageCode::E_INVALID_ARGUMENT, $messageKey, [
                '%key%' => $key,
            ]);
        }

        return $key;
    }

    private function __construct()
    {
    }
}
