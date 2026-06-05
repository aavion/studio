<?php

declare(strict_types=1);

namespace App\Core\Validation;

use App\Core\Message\MessageException;
use App\Core\Message\MessageKey;
use Symfony\Component\Uid\Uuid as SymfonyUuid;

final class Uid
{
    public static function assert(string $uid, string $label = 'UID'): string
    {
        if (!SymfonyUuid::isValid($uid)) {
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
