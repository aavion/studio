<?php

declare(strict_types=1);

namespace App\Api\Endpoint;

use App\Api\ApiMessageKey;
use App\Core\Message\MessageException;
use App\Core\Package\ExtensionPackageIdentity;

final class PackageApiEndpointPath
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
        return '/api/v1/packages/'.self::slug($packageName).'/'.ltrim($path, '/');
    }

    private function __construct()
    {
    }
}
