<?php

declare(strict_types=1);

namespace App\Core\Package;

final class PackagePathPatternScope
{
    public static function isScopedToPrefix(string $pathPattern, string $expectedPrefix): bool
    {
        $body = self::pathPatternBody($pathPattern);
        if (null === $body) {
            return false;
        }

        $delimiter = $pathPattern[0] ?? '#';
        $expectedStarts = ['^'.$expectedPrefix, '^'.preg_quote($expectedPrefix, $delimiter)];

        return self::startsWithAny($body, $expectedStarts)
            && !self::containsTopLevelAlternation($body);
    }

    private static function pathPatternBody(string $pathPattern): ?string
    {
        if ('' === $pathPattern) {
            return null;
        }

        $delimiter = $pathPattern[0];
        if (ctype_alnum($delimiter) || '\\' === $delimiter || ctype_space($delimiter)) {
            return null;
        }

        $end = strrpos($pathPattern, $delimiter);
        if (false === $end || 0 === $end) {
            return null;
        }

        return substr($pathPattern, 1, $end - 1);
    }

    /**
     * @param list<string> $prefixes
     */
    private static function startsWithAny(string $value, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function containsTopLevelAlternation(string $body): bool
    {
        $escaped = false;
        $classDepth = 0;
        $groupDepth = 0;

        foreach (str_split($body) as $char) {
            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ('\\' === $char) {
                $escaped = true;
                continue;
            }

            if ('[' === $char) {
                ++$classDepth;
                continue;
            }

            if (']' === $char && $classDepth > 0) {
                --$classDepth;
                continue;
            }

            if ($classDepth > 0) {
                continue;
            }

            if ('(' === $char) {
                ++$groupDepth;
                continue;
            }

            if (')' === $char && $groupDepth > 0) {
                --$groupDepth;
                continue;
            }

            if ('|' === $char && 0 === $groupDepth) {
                return true;
            }
        }

        return false;
    }

    private function __construct()
    {
    }
}
