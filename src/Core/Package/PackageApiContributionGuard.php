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

        self::assertPathPattern($expectedPrefix, $definition->pathPattern());

        if (null === $definition->handlerKey()) {
            throw MessageException::invalidArgument(ApiMessageKey::API_ENDPOINT_HANDLER_INVALID, [
                '%handler%' => '',
            ]);
        }

        self::assertHandlerKey($package, $definition->handlerKey());
        self::assertTags($package, $definition->tags());
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

    private static function assertPathPattern(string $expectedPrefix, ?string $pathPattern): void
    {
        if (null === $pathPattern) {
            return;
        }

        if (PackagePathPatternScope::isScopedToPrefix($pathPattern, $expectedPrefix)) {
            return;
        }

        throw MessageException::invalidArgument(ApiMessageKey::API_ENDPOINT_PATH_INVALID, [
            '%path%' => $pathPattern,
        ]);
    }

    /**
     * @param list<string> $tags
     */
    private static function assertTags(ExtensionPackage $package, array $tags): void
    {
        $prefix = 'packages-'.PackageApiEndpointPath::slug($package->packageName()).'-';

        if ([] === $tags) {
            throw MessageException::invalidArgument(ApiMessageKey::API_ENDPOINT_TAG_INVALID, [
                '%tag%' => '',
            ]);
        }

        foreach ($tags as $tag) {
            if (str_starts_with($tag, $prefix)) {
                continue;
            }

            throw MessageException::invalidArgument(ApiMessageKey::API_ENDPOINT_TAG_INVALID, [
                '%tag%' => $tag,
            ]);
        }
    }

    private function __construct()
    {
    }
}
