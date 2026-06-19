<?php

declare(strict_types=1);

namespace App\Api\Endpoint;

use App\Api\ApiMessageKey;
use App\Core\Message\MessageException;
use App\Core\Extension\ExtensionIdentity;

final class ExtensionApiEndpointPath
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
        return '/api/v1/extensions/'.self::slug($extensionName).'/'.ltrim($path, '/');
    }

    private function __construct()
    {
    }
}
