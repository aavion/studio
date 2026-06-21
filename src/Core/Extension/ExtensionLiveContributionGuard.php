<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Api\ApiMessageKey;
use App\Core\Message\MessageException;
use App\Entity\Extension;
use App\Live\LiveEndpointDefinition;
use App\Live\LiveEndpointHandlerInterface;
use App\Live\ExtensionLiveEndpointPath;

final class ExtensionLiveContributionGuard
{
    private const RESERVED_SLUGS = ['alerts', 'operations'];

    public static function assertEndpoint(Extension $extension, LiveEndpointDefinition $definition): void
    {
        if ($definition->owner() !== $extension->extensionName()) {
            throw MessageException::invalidArgument(ApiMessageKey::API_ENDPOINT_OWNER_INVALID, [
                '%owner%' => $definition->owner(),
            ]);
        }

        $slug = ExtensionLiveEndpointPath::slug($extension->extensionName());

        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_LIVE_ENDPOINT_RESERVED, [
                '%extension%' => $extension->extensionName(),
                '%slug%' => $slug,
            ]);
        }

        $expectedPrefix = ExtensionLiveEndpointPath::prefix($extension->extensionName());
        if ($definition->path() === $expectedPrefix || !str_starts_with($definition->path(), $expectedPrefix)) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_LIVE_ENDPOINT_PATH_INVALID, [
                '%extension%' => $extension->extensionName(),
                '%path%' => $definition->path(),
            ]);
        }

        self::assertPathPattern($expectedPrefix, $definition->pathPattern());
        self::assertHandlerKey($extension, $definition->handlerKey());
    }

    public static function assertHandler(Extension $extension, LiveEndpointHandlerInterface $handler): void
    {
        self::assertHandlerKey($extension, $handler->liveEndpointHandlerKey());
    }

    private static function assertHandlerKey(Extension $extension, string $handlerKey): void
    {
        $prefix = 'extensions.'.ExtensionLiveEndpointPath::slug($extension->extensionName()).'.live.';

        if (str_starts_with($handlerKey, $prefix)) {
            return;
        }

        throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_LIVE_ENDPOINT_HANDLER_INVALID, [
            '%extension%' => $extension->extensionName(),
            '%handler%' => $handlerKey,
        ]);
    }

    private static function assertPathPattern(string $expectedPrefix, ?string $pathPattern): void
    {
        if (null === $pathPattern) {
            return;
        }

        if (ExtensionPathPatternScope::isScopedToPrefix($pathPattern, $expectedPrefix)) {
            return;
        }

        throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_LIVE_ENDPOINT_PATH_INVALID, [
            '%extension%' => '',
            '%path%' => $pathPattern,
        ]);
    }

    private function __construct()
    {
    }
}
