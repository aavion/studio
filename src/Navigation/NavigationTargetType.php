<?php

declare(strict_types=1);

namespace App\Navigation;

final class NavigationTargetType
{
    public const CONTENT = 'content';
    public const ROUTE = 'route';
    public const URL = 'url';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::CONTENT,
            self::ROUTE,
            self::URL,
        ];
    }

    public static function isSupported(string $targetType): bool
    {
        return in_array($targetType, self::values(), true);
    }
}
