<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Manifest\ManifestSpec;

final class ExtensionManifestSpec
{
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
}
