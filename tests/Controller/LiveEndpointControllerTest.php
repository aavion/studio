<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\LiveEndpointController;
use App\Core\Access\AccessLevel;
use App\Core\Output\JsonOutputRenderer;
use App\Entity\UserAccount;
use App\Live\LiveEndpointDefinition;
use App\Live\LiveEndpointHandlerInterface;
use App\Live\LiveEndpointHandlerRegistry;
use App\Live\LiveEndpointProviderInterface;
use App\Live\LiveEndpointRegistry;
use App\Security\UserRole;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LiveEndpointControllerTest extends TestCase
{
    public function testItEnforcesLiveEndpointMinimumAccessLevel(): void
    {
        $controller = $this->controller($this->endpoint(AccessLevel::ADMIN), $this->user(AccessLevel::USER));

        $response = $controller->dispatch(Request::create('/api/live/demo-pack/admin-action', Request::METHOD_GET));

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertStringContainsString('forbidden', (string) $response->getContent());
    }

    public function testItDispatchesLiveEndpointWhenAccessLevelMatches(): void
    {
        $controller = $this->controller($this->endpoint(AccessLevel::ADMIN), $this->user(AccessLevel::ADMIN));

        $response = $controller->dispatch(Request::create('/api/live/demo-pack/admin-action', Request::METHOD_GET));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('{"status":"ok","next_poll_ms":0}', (string) $response->getContent());
    }

    public function testExplicitMinimumAccessLevelWinsOverPublicFlag(): void
    {
        $endpoint = new LiveEndpointDefinition(
            'extension',
            Request::METHOD_GET,
            '/api/live/demo-pack/admin-action',
            'api_live_extension_dispatch',
            'runAdminAction',
            'Run an admin live action.',
            'extensions.demo-pack.live.admin_action',
            minimumAccessLevel: AccessLevel::ADMIN,
        );
        $controller = $this->controller($endpoint, null);

        $response = $controller->dispatch(Request::create('/api/live/demo-pack/admin-action', Request::METHOD_GET));

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testItRejectsPatternMatchesOutsideTheRequestedExtensionSlug(): void
    {
        $endpoint = new LiveEndpointDefinition(
            'extension',
            Request::METHOD_GET,
            '/api/live/demo-pack/admin-action',
            'api_live_extension_dispatch',
            'runAdminAction',
            'Run an admin live action.',
            'extensions.demo-pack.live.admin_action',
            pathPattern: '#^/api/live/other-pack/.*$#',
        );
        $controller = $this->controller($endpoint, null, expectsUser: false);

        $response = $controller->dispatch(Request::create('/api/live/other-pack/admin-action', Request::METHOD_GET));

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    private function controller(LiveEndpointDefinition $endpoint, ?UserAccount $user, bool $expectsUser = true): LiveEndpointController
    {
        $handler = new class implements LiveEndpointHandlerInterface {
            public function liveEndpointHandlerKey(): string
            {
                return 'extensions.demo-pack.live.admin_action';
            }

            public function handleLiveRequest(Request $request, LiveEndpointDefinition $endpoint): Response
            {
                return new JsonResponse(['status' => 'ok', 'next_poll_ms' => 0]);
            }
        };
        $provider = new class($endpoint) implements LiveEndpointProviderInterface {
            public function __construct(private LiveEndpointDefinition $endpoint)
            {
            }

            public function liveEndpoints(): array
            {
                return [$this->endpoint];
            }
        };
        $security = $this->createMock(Security::class);
        $security->expects($expectsUser ? $this->once() : $this->never())->method('getUser')->willReturn($user);

        return new LiveEndpointController(
            new LiveEndpointRegistry([$provider]),
            new LiveEndpointHandlerRegistry([$handler]),
            new JsonOutputRenderer(),
            $security,
            $this->translator(),
        );
    }

    private function endpoint(int $minimumAccessLevel): LiveEndpointDefinition
    {
        return new LiveEndpointDefinition(
            'extension',
            Request::METHOD_GET,
            '/api/live/demo-pack/admin-action',
            'api_live_extension_dispatch',
            'runAdminAction',
            'Run an admin live action.',
            'extensions.demo-pack.live.admin_action',
            minimumAccessLevel: $minimumAccessLevel,
        );
    }

    private function user(int $accessLevel): UserAccount
    {
        return new UserAccount(
            '10000000-0000-7000-8000-0000000000'.str_pad((string) $accessLevel, 2, '0', STR_PAD_LEFT),
            'liveuser'.$accessLevel,
            'liveuser'.$accessLevel.'@example.test',
            'hash',
            role: UserRole::fromAccessLevel($accessLevel),
        );
    }

    private function translator(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return strtr((string) $id, $parameters);
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };
    }
}
