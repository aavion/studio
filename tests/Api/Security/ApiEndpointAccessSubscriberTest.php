<?php

declare(strict_types=1);

namespace App\Tests\Api\Security;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointProviderInterface;
use App\Api\Endpoint\ApiEndpointRegistry;
use App\Api\Http\ApiRequestContext;
use App\Api\Http\ApiResponder;
use App\Api\Security\ApiEndpointAccessSubscriber;
use App\Core\Output\JsonOutputRenderer;
use App\Tests\Support\IdentityTranslator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ApiEndpointAccessSubscriberTest extends TestCase
{
    public function testItAttachesAnonymousContextForPublicReadEndpoints(): void
    {
        $request = $this->request('GET', '/api/v1/public-status', 'api_public_status');
        $event = new RequestEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber()->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
        $context = ApiRequestContext::fromRequest($request);
        self::assertInstanceOf(ApiRequestContext::class, $context);
        self::assertFalse($context->isAuthenticated());
        self::assertSame(0, $context->actor()->accessLevel());
    }

    public function testItRejectsAnonymousAccessToPrivateEndpoints(): void
    {
        $request = $this->request('GET', '/api/v1/private-status', 'api_private_status');
        $event = new RequestEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber()->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_UNAUTHORIZED, $event->getResponse()->getStatusCode());
        self::assertSame('Bearer realm="Studio API"', $event->getResponse()->headers->get('WWW-Authenticate'));
    }

    public function testItRejectsAnonymousMutationsEvenWhenEndpointAllowsPublic(): void
    {
        $request = $this->request('POST', '/api/v1/public-mutation', 'api_public_mutation');
        $event = new RequestEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber()->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_UNAUTHORIZED, $event->getResponse()->getStatusCode());
    }

    private function subscriber(): ApiEndpointAccessSubscriber
    {
        return new ApiEndpointAccessSubscriber(
            new ApiEndpointRegistry([$this->provider()]),
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
                        '/api/v1/public-status',
                        'api_public_status',
                        'getPublicStatus',
                        'Public status.',
                        allowPublic: true,
                    ),
                    new ApiEndpointDefinition(
                        'system',
                        'POST',
                        '/api/v1/public-mutation',
                        'api_public_mutation',
                        'postPublicMutation',
                        'Public mutation.',
                        allowPublic: true,
                    ),
                    new ApiEndpointDefinition(
                        'system',
                        'GET',
                        '/api/v1/private-status',
                        'api_private_status',
                        'getPrivateStatus',
                        'Private status.',
                    ),
                ];
            }
        };
    }

    private function request(string $method, string $path, string $route): Request
    {
        $request = Request::create($path, $method);
        $request->attributes->set('_route', $route);

        return $request;
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
