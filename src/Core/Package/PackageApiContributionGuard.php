<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Api\ApiMessageKey;
use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Endpoint\PackageApiEndpointPath;
use App\Core\Message\MessageException;
use App\Entity\ExtensionPackage;

final class PackageApiContributionGuard
{
    public static function assertEndpoint(ExtensionPackage $package, ApiEndpointDefinition $definition): void
    {
        $expectedPrefix = PackageApiEndpointPath::path($package->packageName(), '');

        if (!str_starts_with($definition->path(), $expectedPrefix)) {
            throw MessageException::invalidArgument(ApiMessageKey::API_ENDPOINT_PATH_INVALID, [
                '%path%' => $definition->path(),
            ]);
        }

        if (null === $definition->handlerKey()) {
            throw MessageException::invalidArgument(ApiMessageKey::API_ENDPOINT_HANDLER_INVALID, [
                '%handler%' => '',
            ]);
        }

        self::assertHandlerKey($package, $definition->handlerKey());
    }

    public static function assertHandler(ExtensionPackage $package, ApiEndpointHandlerInterface $handler): void
    {
        self::assertHandlerKey($package, $handler->apiEndpointHandlerKey());
    }

    private static function assertHandlerKey(ExtensionPackage $package, string $handlerKey): void
    {
        $prefix = 'packages.'.PackageApiEndpointPath::slug($package->packageName()).'.';

        if (!str_starts_with($handlerKey, $prefix)) {
            throw MessageException::invalidArgument(ApiMessageKey::API_ENDPOINT_HANDLER_INVALID, [
                '%handler%' => $handlerKey,
            ]);
        }
    }

    private function __construct()
    {
    }
}
