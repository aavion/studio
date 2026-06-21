<?php

declare(strict_types=1);

namespace App\Core\Extension;

final readonly class ExtensionTranslationKey
{
    private const MAX_KEY_LENGTH = 180;

    private function __construct()
    {
    }

    public static function isOwnedBy(string $extensionName, string $key): bool
    {
        $key = trim($key);
        if (
            '' === $key
            || strlen($key) > self::MAX_KEY_LENGTH
            || !ExtensionManifestSpec::isValidSlug($extensionName)
        ) {
            return false;
        }

        return 1 === preg_match(
            '/^ext\.'.preg_quote($extensionName, '/').'\.(?:[a-z][a-z0-9_]*)(?:\.[a-z][a-z0-9_]*)*$/',
            $key,
        );
    }
}
