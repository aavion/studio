<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Api\ApiMessageKey;
use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Endpoint\ExtensionApiEndpointPath;
use App\Core\Message\MessageException;
use App\Entity\Extension;

final class ExtensionApiContributionGuard
{
    public static function assertEndpoint(Extension $extension, ApiEndpointDefinition $definition): void
    {
        $expectedPrefix = ExtensionApiEndpointPath::path($extension->extensionName(), '');

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

        self::assertHandlerKey($extension, $definition->handlerKey());
        self::assertTags($extension, $definition->tags());
    }

    public static function assertHandler(Extension $extension, ApiEndpointHandlerInterface $handler): void
    {
        self::assertHandlerKey($extension, $handler->apiEndpointHandlerKey());
    }

    private static function assertHandlerKey(Extension $extension, string $handlerKey): void
    {
        $prefix = 'extensions.'.ExtensionApiEndpointPath::slug($extension->extensionName()).'.';

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

        if (ExtensionPathPatternScope::isScopedToPrefix($pathPattern, $expectedPrefix)) {
            return;
        }

        throw MessageException::invalidArgument(ApiMessageKey::API_ENDPOINT_PATH_INVALID, [
            '%path%' => $pathPattern,
        ]);
    }

    /**
     * @param list<string> $tags
     */
    private static function assertTags(Extension $extension, array $tags): void
    {
        $prefix = 'extensions-'.ExtensionApiEndpointPath::slug($extension->extensionName()).'-';

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
