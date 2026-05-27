<?php

declare(strict_types=1);

namespace App\Core\Validation;

use App\Core\Message\MessageException;
use App\Core\Message\MessageKey;

final class Uid
{
    public static function assert(string $uid, string $label = 'UID'): string
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uid)) {
            throw MessageException::invalidArgument(MessageKey::CONTENT_UID_INVALID, [
                '%label%' => $label,
                '%uid%' => $uid,
            ]);
        }

        return $uid;
    }

    private function __construct()
    {
    }
}
