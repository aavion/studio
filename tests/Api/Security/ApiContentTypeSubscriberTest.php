<?php

declare(strict_types=1);

namespace App\Tests\Api\Security;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointProviderInterface;
use App\Api\Endpoint\ApiEndpointRegistry;
use App\Api\Http\ApiResponder;
use App\Api\Security\ApiContentTypeSubscriber;
use App\Core\Output\JsonOutputRenderer;
use App\Tests\Support\IdentityTranslator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ApiContentTypeSubscriberTest extends TestCase
{
    public function testItRejectsRequestSchemaEndpointsWithoutJsonContentType(): void
    {
        $request = Request::create('/api/v1/example', 'PATCH', server: [
            'CONTENT_TYPE' => 'text/plain',
        ]);
        $event = new RequestEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber()->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, $event->getResponse()->getStatusCode());
        $payload = json_decode((string) $event->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('api.unsupported_media_type', $payload['error']['code']);
        self::assertSame('application/json', $payload['error']['context']['expected_content_type']);
    }

    public function testItAllowsJsonContentTypes(): void
    {
        $request = Request::create('/api/v1/example', 'PATCH', server: [
            'CONTENT_TYPE' => 'application/vnd.api+json; charset=utf-8',
        ]);
        $event = new RequestEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber()->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testItIgnoresEndpointsWithoutRequestSchema(): void
    {
        $request = Request::create('/api/v1/read', 'GET');
        $event = new RequestEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber()->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    private function subscriber(): ApiContentTypeSubscriber
    {
        return new ApiContentTypeSubscriber(
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
                        'PATCH',
                        '/api/v1/example',
                        'api_example',
                        'updateExample',
                        'Update example.',
                        requestSchema: ['type' => 'object'],
                    ),
                    new ApiEndpointDefinition(
                        'system',
                        'GET',
                        '/api/v1/read',
                        'api_read',
                        'readExample',
                        'Read example.',
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
