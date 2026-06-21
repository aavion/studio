<?php

declare(strict_types=1);

namespace App\Core\Validation;

final readonly class IdentifierSpec
{
    public const MAX_SLUG_LENGTH = 60;
    public const MAX_DOT_PATH_IDENTIFIER_LENGTH = 160;
    public const MAX_SNAKE_IDENTIFIER_LENGTH = 120;
    public const MAX_MACHINE_IDENTIFIER_LENGTH = 160;
    public const MAX_PORTABLE_DATABASE_IDENTIFIER_LENGTH = 60;

    public const CANONICAL_UUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
    public const OWNER_SLUG_PATTERN = '[a-z][a-z0-9]*(?:-[a-z0-9]+)*';
    public const CONTENT_SLUG_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';
    public const SNAKE_IDENTIFIER_PATTERN = '[a-z][a-z0-9_]*';
    public const PASCAL_IDENTIFIER_PATTERN = '[A-Za-z][A-Za-z0-9_]*';
    public const ACL_GROUP_IDENTIFIER_PATTERN = '[a-z][a-z0-9_]{2,79}';
    public const DOT_PATH_IDENTIFIER_PATTERN = '[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+';
    public const HANDLER_KEY_PATTERN = '[a-z0-9][a-z0-9_.-]*';
    public const MACHINE_IDENTIFIER_PATTERN = '[a-z0-9][a-z0-9_.:-]*';
    public const DATABASE_PREFIX_PATTERN = '[a-z][a-z0-9_]*';
    public const DATABASE_TABLE_PREFIX_PATTERN = '[a-z][a-z0-9_]*_';

    public static function isCanonicalUuid(string $value): bool
    {
        return self::matches($value, self::CANONICAL_UUID_PATTERN);
    }

    public static function isOwnerSlug(string $value): bool
    {
        return self::matches($value, self::OWNER_SLUG_PATTERN, self::MAX_SLUG_LENGTH);
    }

    public static function isContentSlug(string $value): bool
    {
        return self::matches($value, self::CONTENT_SLUG_PATTERN, self::MAX_SLUG_LENGTH);
    }

    public static function isSnakeIdentifier(string $value, int $maxLength = self::MAX_SNAKE_IDENTIFIER_LENGTH): bool
    {
        return self::matches($value, self::SNAKE_IDENTIFIER_PATTERN, $maxLength);
    }

    public static function isPortableDatabaseIdentifier(string $value): bool
    {
        return self::isSnakeIdentifier($value, self::MAX_PORTABLE_DATABASE_IDENTIFIER_LENGTH);
    }

    public static function isPascalIdentifier(string $value): bool
    {
        return self::matches($value, self::PASCAL_IDENTIFIER_PATTERN);
    }

    public static function isAclGroupIdentifier(string $value): bool
    {
        return self::matches($value, self::ACL_GROUP_IDENTIFIER_PATTERN);
    }

    public static function isDotPathIdentifier(string $value, int $maxLength = self::MAX_DOT_PATH_IDENTIFIER_LENGTH): bool
    {
        return self::matches($value, self::DOT_PATH_IDENTIFIER_PATTERN, $maxLength);
    }

    public static function isHandlerKey(string $value): bool
    {
        return self::matches($value, self::HANDLER_KEY_PATTERN);
    }

    public static function isMachineIdentifier(
        string $value,
        int $minLength = 1,
        int $maxLength = self::MAX_MACHINE_IDENTIFIER_LENGTH,
    ): bool {
        return strlen($value) >= $minLength
            && self::matches($value, self::MACHINE_IDENTIFIER_PATTERN, $maxLength);
    }

    public static function isDatabasePrefix(string $value): bool
    {
        return self::matches($value, self::DATABASE_PREFIX_PATTERN);
    }

    public static function isDatabaseTablePrefix(string $value): bool
    {
        return self::matches($value, self::DATABASE_TABLE_PREFIX_PATTERN);
    }

    private static function matches(string $value, string $pattern, ?int $maxLength = null): bool
    {
        return (null === $maxLength || strlen($value) <= $maxLength)
            && 1 === preg_match('/^'.$pattern.'$/', $value);
    }

    private function __construct()
    {
    }
}
