<?php

declare(strict_types=1);

namespace App\Core\Access;

use App\Core\Message\MessageException;
use App\Core\Message\MessageKey;

final class AccessLevel
{
    public const PUBLIC = 0;
    public const USER = 1;
    public const MODERATOR = 2;
    public const AUTHOR = 3;
    public const PUBLISHER = 4;
    public const CURATOR = 5;
    public const MANAGER = 6;
    public const DIRECTOR = 7;
    public const ADMIN = 8;
    public const OWNER = 9;

    public const DEFAULT_VIEW = self::PUBLIC;
    public const DEFAULT_EDIT = self::AUTHOR;
    public const DEFAULT_MANAGE = self::MANAGER;

    public static function assert(?int $level): ?int
    {
        if (null === $level) {
            return null;
        }

        if ($level < self::PUBLIC || $level > self::OWNER) {
            throw MessageException::invalidArgument(MessageKey::ACCESS_LEVEL_INVALID, [
                '%level%' => $level,
            ]);
        }

        return $level;
    }

    private function __construct()
    {
    }
}
