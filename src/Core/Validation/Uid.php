<?php

declare(strict_types=1);

namespace App\Core\Validation;

use App\Content\ContentMessageKey;
use App\Core\Message\MessageException;
use Symfony\Component\Uid\Uuid as SymfonyUuid;

final class Uid
{
    public static function assert(string $uid, string $label = 'UID'): string
    {
        if (!SymfonyUuid::isValid($uid)) {
            throw MessageException::invalidArgument(ContentMessageKey::CONTENT_UID_INVALID, [
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
