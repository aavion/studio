<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Api\ApiMessageKey;
use App\Core\Access\AccessLevel;
use App\Core\Message\MessageException;
use App\Core\Extension\ExtensionStatus;
use App\Core\Extension\ExtensionLiveContributionGuard;
use App\Core\Extension\ExtensionRuntimeContributionRegistry;
use App\Core\Extension\ExtensionScope;
use App\Entity\Extension;
use App\Live\LiveEndpointDefinition;
use App\Live\LiveEndpointHandlerInterface;
use App\Live\ExtensionLiveEndpointPath;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class ExtensionLiveContributionGuardTest extends TestCase
{
    public function testItAcceptsExtensionLiveContributionNamespace(): void
    {
        $extension = $this->extension('captcha-pack');

        ExtensionLiveContributionGuard::assertEndpoint($extension, new LiveEndpointDefinition(
            'extension',
            Request::METHOD_GET,
            ExtensionLiveEndpointPath::path($extension->extensionName(), 'seed'),
            'api_live_extension_dispatch',
            'getCaptchaSeed',
            'Return a captcha seed.',
            'extensions.captcha-pack.live.seed',
        ));

        self::addToAssertionCount(1);
    }

    public function testItRejectsReservedSystemLiveSlugs(): void
    {
        $extension = $this->extension('alerts');

        $this->expectException(MessageException::class);

        ExtensionLiveContributionGuard::assertEndpoint($extension, new LiveEndpointDefinition(
            'extension',
            Request::METHOD_GET,
            ExtensionLiveEndpointPath::path($extension->extensionName(), 'demo'),
            'api_live_extension_dispatch',
            'getAlertDemo',
            'Return alert demo payload.',
            'extensions.alerts.live.demo',
        ));
    }

    public function testItRejectsPathsOutsideOwnedLiveNamespace(): void
    {
        $extension = $this->extension('captcha-pack');

        $this->expectException(MessageException::class);

        ExtensionLiveContributionGuard::assertEndpoint($extension, new LiveEndpointDefinition(
            'extension',
            Request::METHOD_GET,
            '/api/live/other-pack/seed',
            'api_live_extension_dispatch',
            'getCaptchaSeed',
            'Return a captcha seed.',
            'extensions.captcha-pack.live.seed',
        ));
    }

    public function testItRejectsExtensionLiveRootPaths(): void
    {
        $extension = $this->extension('captcha-pack');

        $this->expectException(MessageException::class);

        ExtensionLiveContributionGuard::assertEndpoint($extension, new LiveEndpointDefinition(
            'extension',
            Request::METHOD_GET,
            '/api/live/captcha-pack/',
            'api_live_extension_dispatch',
            'getCaptchaRoot',
            'Return a captcha root payload.',
            'extensions.captcha-pack.live.root',
        ));
    }

    public function testExtensionLivePathHelperRejectsEmptyResourcePaths(): void
    {
        $this->expectException(MessageException::class);

        ExtensionLiveEndpointPath::path('captcha-pack', '');
    }

    public function testItRejectsLivePatternsThatEscapeOwnedNamespace(): void
    {
        $extension = $this->extension('captcha-pack');

        $this->expectException(MessageException::class);

        ExtensionLiveContributionGuard::assertEndpoint($extension, new LiveEndpointDefinition(
            'extension',
            Request::METHOD_GET,
            ExtensionLiveEndpointPath::path($extension->extensionName(), 'seed'),
            'api_live_extension_dispatch',
            'getCaptchaSeed',
            'Return a captcha seed.',
            'extensions.captcha-pack.live.seed',
            pathPattern: '#^/api/live/captcha-pack/.*|^/api/live/other-pack/#',
        ));
    }

    public function testItAllowsGroupedLivePatternAlternationInsideOwnedNamespace(): void
    {
        $extension = $this->extension('captcha-pack');

        ExtensionLiveContributionGuard::assertEndpoint($extension, new LiveEndpointDefinition(
            'extension',
            Request::METHOD_GET,
            ExtensionLiveEndpointPath::path($extension->extensionName(), 'seed'),
            'api_live_extension_dispatch',
            'getCaptchaSeed',
            'Return a captcha seed.',
            'extensions.captcha-pack.live.seed',
            pathPattern: '#^/api/live/captcha-pack/(seed|refresh)$#',
        ));

        self::addToAssertionCount(1);
    }

    public function testItRejectsForeignHandlerNamespaces(): void
    {
        $extension = $this->extension('captcha-pack');

        $this->expectException(MessageException::class);

        ExtensionLiveContributionGuard::assertEndpoint($extension, new LiveEndpointDefinition(
            'extension',
            Request::METHOD_GET,
            ExtensionLiveEndpointPath::path($extension->extensionName(), 'seed'),
            'api_live_extension_dispatch',
            'getCaptchaSeed',
            'Return a captcha seed.',
            'extensions.captcha-pack.seed',
        ));
    }

    public function testItRejectsMutatingLiveEndpointMethods(): void
    {
        $this->expectException(MessageException::class);
        $this->expectExceptionMessage(ApiMessageKey::API_ENDPOINT_METHOD_INVALID);

        new LiveEndpointDefinition(
            'extension',
            Request::METHOD_POST,
            ExtensionLiveEndpointPath::path('captcha-pack', 'seed'),
            'api_live_extension_dispatch',
            'refreshCaptchaSeed',
            'Refresh a captcha seed.',
            'extensions.captcha-pack.live.seed',
            minimumAccessLevel: AccessLevel::PUBLIC,
        );
    }

    public function testRuntimeRegistryExposesLiveEndpointAndHandlerContributions(): void
    {
        $extension = $this->extension('captcha-pack');
        $definition = new LiveEndpointDefinition(
            'extension',
            Request::METHOD_GET,
            ExtensionLiveEndpointPath::path($extension->extensionName(), 'seed'),
            'api_live_extension_dispatch',
            'getCaptchaSeed',
            'Return a captcha seed.',
            'extensions.captcha-pack.live.seed',
        );
        $handler = new class implements LiveEndpointHandlerInterface {
            public function liveEndpointHandlerKey(): string
            {
                return 'extensions.captcha-pack.live.seed';
            }

            public function handleLiveRequest(Request $request, LiveEndpointDefinition $endpoint): Response
            {
                return new JsonResponse(['next_poll_ms' => 0]);
            }
        };

        $registry = new ExtensionRuntimeContributionRegistry();
        $registry->add($extension, [$definition, $handler]);

        self::assertSame([$definition], $registry->liveEndpoints());
        self::assertSame([$handler], $registry->liveEndpointHandlers());
    }

    private function extension(string $name): Extension
    {
        return new Extension(
            Uuid::v7()->toRfc4122(),
            [ExtensionScope::Module],
            $name,
            'extensions/'.$name,
            ExtensionStatus::Active,
        );
    }
}
