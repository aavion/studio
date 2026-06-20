<?php

declare(strict_types=1);

namespace App\Core\Extension;

final readonly class ExtensionOwnerName
{
    private const PLAIN_PREFIX_MAX_SLUG_LENGTH = 32;
    private const HASHED_PREFIX_HEAD_LENGTH = 24;
    private const HASHED_PREFIX_HASH_LENGTH = 16;

    public static function prefix(string $extensionName): string
    {
        $normalizedExtensionName = str_replace('-', '_', $extensionName);

        if (strlen($normalizedExtensionName) > self::PLAIN_PREFIX_MAX_SLUG_LENGTH) {
            return sprintf(
                'ext%d_%s_%s_',
                strlen($normalizedExtensionName),
                substr($normalizedExtensionName, 0, self::HASHED_PREFIX_HEAD_LENGTH),
                substr(hash('sha256', $normalizedExtensionName), 0, self::HASHED_PREFIX_HASH_LENGTH),
            );
        }

        return 'ext'.strlen($normalizedExtensionName).'_'.$normalizedExtensionName.'_';
    }
}
