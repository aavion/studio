<?php

declare(strict_types=1);

namespace App\Core\Access;

use App\Core\Message\MessageCode;
use App\Core\Message\MessageException;
use App\Core\Message\MessageKey;

final class AccessLevel
{
    public const PUBLIC = 0;
    public const EDITOR = 3;
    public const MANAGER = 6;
    public const ADMIN = 9;

    public const DEFAULT_VIEW = self::PUBLIC;
    public const DEFAULT_EDIT = self::EDITOR;
    public const DEFAULT_MANAGE = self::MANAGER;

    public static function assert(?int $level): ?int
    {
        if (null === $level) {
            return null;
        }

        if ($level < self::PUBLIC || $level > self::ADMIN) {
            throw MessageException::forMessage(MessageCode::E_INVALID_ARGUMENT, MessageKey::ACCESS_LEVEL_INVALID, [
                '%level%' => $level,
            ]);
        }

        return $level;
    }

    private function __construct()
    {
    }
}
