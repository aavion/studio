<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Manifest\ManifestSpec;

final class ExtensionManifestSpec
{
    public const VERSION_PATTERN = '\d{1,4}(?:\.\d{1,4}){0,2}';

    public static function create(): ManifestSpec
    {
        return ManifestSpec::create()
            ->require('EXTENSION_AUTHOR')
            ->require('EXTENSION_SLUG')
            ->require('EXTENSION_NAME')
            ->require('EXTENSION_VERSION')
            ->require('EXTENSION_SCOPE')
            ->require('EXTENSION_DEPENDENCIES');
    }

    public static function isValidSlug(string $slug): bool
    {
        return ExtensionIdentity::isExtensionName($slug);
    }

    public static function isValidVersion(string $version): bool
    {
        return 1 === preg_match('/^'.self::VERSION_PATTERN.'$/', $version);
    }
}
