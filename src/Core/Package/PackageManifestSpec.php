<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Manifest\ManifestSpec;

final class PackageManifestSpec
{
    public static function create(): ManifestSpec
    {
        return ManifestSpec::create()
            ->require('PACKAGE_AUTHOR')
            ->require('PACKAGE_SLUG')
            ->require('PACKAGE_NAME')
            ->require('PACKAGE_VERSION')
            ->require('PACKAGE_SCOPE')
            ->require('PACKAGE_DEPENDENCIES');
    }

    public static function isValidSlug(string $slug): bool
    {
        return ExtensionPackageIdentity::isPackageName($slug);
    }
}
