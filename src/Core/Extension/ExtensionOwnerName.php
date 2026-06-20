<?php

declare(strict_types=1);

namespace App\Core\Extension;

final readonly class ExtensionOwnerName
{
    public static function prefix(string $extensionName): string
    {
        $normalizedExtensionName = str_replace('-', '_', $extensionName);

        return 'ext'.strlen($normalizedExtensionName).'_'.$normalizedExtensionName.'_';
    }
}
