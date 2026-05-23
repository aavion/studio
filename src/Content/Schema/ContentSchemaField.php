<?php

declare(strict_types=1);

namespace App\Content\Schema;

final class ContentSchemaField
{
    public const TITLE = 'title';
    public const SUBTITLE = 'subtitle';

    /**
     * @var list<string>
     */
    public const REQUIRED_BASE_IDENTIFIERS = [
        self::TITLE,
        self::SUBTITLE,
    ];

    /**
     * @return list<string>
     */
    public static function requiredBaseIdentifiers(): array
    {
        return self::REQUIRED_BASE_IDENTIFIERS;
    }

    public static function isRequiredBaseIdentifier(string $identifier): bool
    {
        return in_array($identifier, self::REQUIRED_BASE_IDENTIFIERS, true);
    }

    private function __construct()
    {
    }
}
