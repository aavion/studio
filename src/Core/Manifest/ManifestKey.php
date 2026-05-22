<?php

declare(strict_types=1);

namespace App\Core\Manifest;

final class ManifestKey
{
    private const PATTERN = '/^[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)*$/';

    public static function isValid(string $key): bool
    {
        return 1 === preg_match(self::PATTERN, $key);
    }

    public static function pattern(): string
    {
        return self::PATTERN;
    }
}
