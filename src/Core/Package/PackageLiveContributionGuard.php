<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\MessageException;
use App\Entity\ExtensionPackage;
use App\Live\LiveEndpointDefinition;
use App\Live\LiveEndpointHandlerInterface;
use App\Live\PackageLiveEndpointPath;

final class PackageLiveContributionGuard
{
    private const RESERVED_SLUGS = ['alerts', 'operations'];

    public static function assertEndpoint(ExtensionPackage $package, LiveEndpointDefinition $definition): void
    {
        $slug = PackageLiveEndpointPath::slug($package->packageName());

        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            throw MessageException::invalidArgument(PackageMessageKey::PACKAGE_LIVE_ENDPOINT_RESERVED, [
                '%package%' => $package->packageName(),
                '%slug%' => $slug,
            ]);
        }

        $expectedPrefix = PackageLiveEndpointPath::path($package->packageName(), '');
        if (!str_starts_with($definition->path(), $expectedPrefix)) {
            throw MessageException::invalidArgument(PackageMessageKey::PACKAGE_LIVE_ENDPOINT_PATH_INVALID, [
                '%package%' => $package->packageName(),
                '%path%' => $definition->path(),
            ]);
        }

        self::assertPathPattern($expectedPrefix, $definition->pathPattern());
        self::assertHandlerKey($package, $definition->handlerKey());
    }

    public static function assertHandler(ExtensionPackage $package, LiveEndpointHandlerInterface $handler): void
    {
        self::assertHandlerKey($package, $handler->liveEndpointHandlerKey());
    }

    private static function assertHandlerKey(ExtensionPackage $package, string $handlerKey): void
    {
        $prefix = 'packages.'.PackageLiveEndpointPath::slug($package->packageName()).'.live.';

        if (str_starts_with($handlerKey, $prefix)) {
            return;
        }

        throw MessageException::invalidArgument(PackageMessageKey::PACKAGE_LIVE_ENDPOINT_HANDLER_INVALID, [
            '%package%' => $package->packageName(),
            '%handler%' => $handlerKey,
        ]);
    }

    private static function assertPathPattern(string $expectedPrefix, ?string $pathPattern): void
    {
        if (null === $pathPattern) {
            return;
        }

        if (PackagePathPatternScope::isScopedToPrefix($pathPattern, $expectedPrefix)) {
            return;
        }

        throw MessageException::invalidArgument(PackageMessageKey::PACKAGE_LIVE_ENDPOINT_PATH_INVALID, [
            '%package%' => '',
            '%path%' => $pathPattern,
        ]);
    }

    private function __construct()
    {
    }
}
