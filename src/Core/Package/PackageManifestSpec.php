<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Manifest\ManifestSpec;

final class PackageManifestSpec
{
    public static function create(): ManifestSpec
    {
        return ManifestSpec::create()
            ->allowOnly(
                'PACKAGE_AUTHOR',
                'PACKAGE_SLUG',
                'PACKAGE_NAME',
                'PACKAGE_VERSION',
                'PACKAGE_SCOPE',
                'PACKAGE_DEPENDENCIES',
                'PACKAGE_SOURCE',
                'PACKAGE_CHANNEL',
                'PACKAGE_IMAGE',
                'PACKAGE_NAMESPACE',
                'PACKAGE_DESCRIPTION',
                'PACKAGE_LICENSE',
                'PACKAGE_HOMEPAGE',
            )
            ->require('PACKAGE_AUTHOR')
            ->require('PACKAGE_SLUG')
            ->require('PACKAGE_NAME')
            ->require('PACKAGE_VERSION')
            ->require('PACKAGE_SCOPE')
            ->require('PACKAGE_DEPENDENCIES');
    }

    public static function isValidSlug(string $slug): bool
    {
        return 1 === preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $slug);
    }
}
