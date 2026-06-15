<?php

declare(strict_types=1);

namespace App\Live;

use App\Api\ApiMessageKey;
use App\Core\Message\MessageException;
use App\Core\Package\ExtensionPackageIdentity;

final class PackageLiveEndpointPath
{
    public static function slug(string $packageName): string
    {
        $slug = trim($packageName);

        if (!ExtensionPackageIdentity::isPackageName($slug)) {
            throw MessageException::invalidArgument(ApiMessageKey::API_ENDPOINT_PATH_INVALID, [
                '%path%' => $packageName,
            ]);
        }

        return $slug;
    }

    public static function path(string $packageName, string $path): string
    {
        $path = trim($path, '/');
        if ('' === $path) {
            throw MessageException::invalidArgument(ApiMessageKey::API_ENDPOINT_PATH_INVALID, [
                '%path%' => '/api/live/'.self::slug($packageName).'/',
            ]);
        }

        return self::prefix($packageName).$path;
    }

    public static function prefix(string $packageName): string
    {
        return '/api/live/'.self::slug($packageName).'/';
    }

    private function __construct()
    {
    }
}
