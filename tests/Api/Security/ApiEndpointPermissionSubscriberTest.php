<?php

declare(strict_types=1);

namespace App\Tests\Api\Security;

use App\Api\Endpoint\ApiEndpointAccessPolicy;
use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointProviderInterface;
use App\Api\Endpoint\ApiEndpointRegistry;
use App\Api\Http\ApiResponder;
use App\Api\Security\ApiEndpointPermissionSubscriber;
use App\Core\Access\AccessLevel;
use App\Core\Output\JsonOutputRenderer;
use App\Tests\Support\IdentityTranslator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ApiEndpointPermissionSubscriberTest extends TestCase
{
    public function testItPreservesEarlierAnonymousAuthenticationChallenges(): void
    {
        $request = Request::create('/api/v1/private-status', 'GET');
        $event = new RequestEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST);
        $event->setResponse(new Response('Unauthorized', Response::HTTP_UNAUTHORIZED, [
            'WWW-Authenticate' => 'Bearer realm="System API"',
        ]));

        $this->subscriber()->onKernelRequest($event);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $event->getResponse()->getStatusCode());
        self::assertSame('Bearer realm="System API"', $event->getResponse()->headers->get('WWW-Authenticate'));
    }

    public function testItRejectsInsufficientAccessWhenNoEarlierResponseExists(): void
    {
        $request = Request::create('/api/v1/private-status', 'GET');
        $event = new RequestEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber()->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_FORBIDDEN, $event->getResponse()->getStatusCode());
    }

    private function subscriber(): ApiEndpointPermissionSubscriber
    {
        return new ApiEndpointPermissionSubscriber(
            new ApiEndpointRegistry([$this->provider()]),
            new ApiEndpointAccessPolicy(),
            new ApiResponder(new JsonOutputRenderer(), new IdentityTranslator()),
        );
    }

    private function provider(): ApiEndpointProviderInterface
    {
        return new class implements ApiEndpointProviderInterface {
            public function apiEndpoints(): array
            {
                return [
                    new ApiEndpointDefinition(
                        'system',
                        'GET',
                        '/api/v1/private-status',
                        'api_private_status',
                        'getPrivateStatus',
                        'Private status.',
                        minimumAccessLevel: AccessLevel::USER,
                    ),
                ];
            }
        };
    }

    private function kernel(): HttpKernelInterface
    {
        return new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };
    }
}
