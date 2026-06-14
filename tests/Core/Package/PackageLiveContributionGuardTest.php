<?php

declare(strict_types=1);

namespace App\Tests\Core\Package;

use App\Api\ApiMessageKey;
use App\Core\Access\AccessLevel;
use App\Core\Message\MessageException;
use App\Core\Package\ExtensionPackageStatus;
use App\Core\Package\PackageLiveContributionGuard;
use App\Core\Package\PackageRuntimeContributionRegistry;
use App\Core\Package\PackageScope;
use App\Entity\ExtensionPackage;
use App\Live\LiveEndpointDefinition;
use App\Live\LiveEndpointHandlerInterface;
use App\Live\PackageLiveEndpointPath;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class PackageLiveContributionGuardTest extends TestCase
{
    public function testItAcceptsPackageLiveContributionNamespace(): void
    {
        $package = $this->package('captcha-pack');

        PackageLiveContributionGuard::assertEndpoint($package, new LiveEndpointDefinition(
            'package',
            Request::METHOD_GET,
            PackageLiveEndpointPath::path($package->packageName(), 'seed'),
            'api_live_package_dispatch',
            'getCaptchaSeed',
            'Return a captcha seed.',
            'packages.captcha-pack.live.seed',
        ));

        self::addToAssertionCount(1);
    }

    public function testItRejectsReservedSystemLiveSlugs(): void
    {
        $package = $this->package('alerts');

        $this->expectException(MessageException::class);

        PackageLiveContributionGuard::assertEndpoint($package, new LiveEndpointDefinition(
            'package',
            Request::METHOD_GET,
            PackageLiveEndpointPath::path($package->packageName(), 'demo'),
            'api_live_package_dispatch',
            'getAlertDemo',
            'Return alert demo payload.',
            'packages.alerts.live.demo',
        ));
    }

    public function testItRejectsPathsOutsideOwnedLiveNamespace(): void
    {
        $package = $this->package('captcha-pack');

        $this->expectException(MessageException::class);

        PackageLiveContributionGuard::assertEndpoint($package, new LiveEndpointDefinition(
            'package',
            Request::METHOD_GET,
            '/api/live/other-pack/seed',
            'api_live_package_dispatch',
            'getCaptchaSeed',
            'Return a captcha seed.',
            'packages.captcha-pack.live.seed',
        ));
    }

    public function testItRejectsLivePatternsThatEscapeOwnedNamespace(): void
    {
        $package = $this->package('captcha-pack');

        $this->expectException(MessageException::class);

        PackageLiveContributionGuard::assertEndpoint($package, new LiveEndpointDefinition(
            'package',
            Request::METHOD_GET,
            PackageLiveEndpointPath::path($package->packageName(), 'seed'),
            'api_live_package_dispatch',
            'getCaptchaSeed',
            'Return a captcha seed.',
            'packages.captcha-pack.live.seed',
            pathPattern: '#^/api/live/captcha-pack/.*|^/api/live/other-pack/#',
        ));
    }

    public function testItAllowsGroupedLivePatternAlternationInsideOwnedNamespace(): void
    {
        $package = $this->package('captcha-pack');

        PackageLiveContributionGuard::assertEndpoint($package, new LiveEndpointDefinition(
            'package',
            Request::METHOD_GET,
            PackageLiveEndpointPath::path($package->packageName(), 'seed'),
            'api_live_package_dispatch',
            'getCaptchaSeed',
            'Return a captcha seed.',
            'packages.captcha-pack.live.seed',
            pathPattern: '#^/api/live/captcha-pack/(seed|refresh)$#',
        ));

        self::addToAssertionCount(1);
    }

    public function testItRejectsForeignHandlerNamespaces(): void
    {
        $package = $this->package('captcha-pack');

        $this->expectException(MessageException::class);

        PackageLiveContributionGuard::assertEndpoint($package, new LiveEndpointDefinition(
            'package',
            Request::METHOD_GET,
            PackageLiveEndpointPath::path($package->packageName(), 'seed'),
            'api_live_package_dispatch',
            'getCaptchaSeed',
            'Return a captcha seed.',
            'packages.captcha-pack.seed',
        ));
    }

    public function testItRejectsMutatingLiveEndpointMethods(): void
    {
        $this->expectException(MessageException::class);
        $this->expectExceptionMessage(ApiMessageKey::API_ENDPOINT_METHOD_INVALID);

        new LiveEndpointDefinition(
            'package',
            Request::METHOD_POST,
            PackageLiveEndpointPath::path('captcha-pack', 'seed'),
            'api_live_package_dispatch',
            'refreshCaptchaSeed',
            'Refresh a captcha seed.',
            'packages.captcha-pack.live.seed',
            minimumAccessLevel: AccessLevel::PUBLIC,
        );
    }

    public function testRuntimeRegistryExposesLiveEndpointAndHandlerContributions(): void
    {
        $package = $this->package('captcha-pack');
        $definition = new LiveEndpointDefinition(
            'package',
            Request::METHOD_GET,
            PackageLiveEndpointPath::path($package->packageName(), 'seed'),
            'api_live_package_dispatch',
            'getCaptchaSeed',
            'Return a captcha seed.',
            'packages.captcha-pack.live.seed',
        );
        $handler = new class implements LiveEndpointHandlerInterface {
            public function liveEndpointHandlerKey(): string
            {
                return 'packages.captcha-pack.live.seed';
            }

            public function handleLiveRequest(Request $request, LiveEndpointDefinition $endpoint): Response
            {
                return new JsonResponse(['next_poll_ms' => 0]);
            }
        };

        $registry = new PackageRuntimeContributionRegistry();
        $registry->add($package, [$definition, $handler]);

        self::assertSame([$definition], $registry->liveEndpoints());
        self::assertSame([$handler], $registry->liveEndpointHandlers());
    }

    private function package(string $name): ExtensionPackage
    {
        return new ExtensionPackage(
            Uuid::v7()->toRfc4122(),
            [PackageScope::Module],
            $name,
            'packages/'.$name,
            ExtensionPackageStatus::Active,
        );
    }
}
