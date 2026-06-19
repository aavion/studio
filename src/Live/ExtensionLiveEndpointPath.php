<?php

declare(strict_types=1);

namespace App\Live;

use App\Api\ApiMessageKey;
use App\Core\Message\MessageException;
use App\Core\Extension\ExtensionIdentity;

final class ExtensionLiveEndpointPath
{
    public static function slug(string $extensionName): string
    {
        $slug = trim($extensionName);

        if (!ExtensionIdentity::isExtensionName($slug)) {
            throw MessageException::invalidArgument(ApiMessageKey::API_ENDPOINT_PATH_INVALID, [
                '%path%' => $extensionName,
            ]);
        }

        return $slug;
    }

    public static function path(string $extensionName, string $path): string
    {
        $path = trim($path, '/');
        if ('' === $path) {
            throw MessageException::invalidArgument(ApiMessageKey::API_ENDPOINT_PATH_INVALID, [
                '%path%' => '/api/live/'.self::slug($extensionName).'/',
            ]);
        }

        return self::prefix($extensionName).$path;
    }

    public static function prefix(string $extensionName): string
    {
        return '/api/live/'.self::slug($extensionName).'/';
    }

    private function __construct()
    {
    }
}
