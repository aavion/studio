<?php

declare(strict_types=1);

namespace App\Tests\Api\Http;

use App\Api\Http\ApiTraceHeaderSubscriber;
use App\Core\Log\AccessRequestMetadata;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ApiTraceHeaderSubscriberTest extends TestCase
{
    public function testItAddsTraceHeadersToVersionedApiResponses(): void
    {
        $request = Request::create('/api/v1/status', server: [
            'HTTP_X_CORRELATION_ID' => 'client-correlation-1',
        ]);
        $response = new Response('ok');

        $this->subscriber()->onKernelResponse($this->responseEvent($request, $response));

        self::assertMatchesRegularExpression('/\A[a-f0-9]{24}\z/', (string) $response->headers->get('X-Request-ID'));
        self::assertSame('client-correlation-1', $response->headers->get('X-Correlation-ID'));
    }

    public function testItOmitsMissingCorrelationHeader(): void
    {
        $request = Request::create('/api/v1/status');
        $response = new Response('ok');

        $this->subscriber()->onKernelResponse($this->responseEvent($request, $response));

        self::assertMatchesRegularExpression('/\A[a-f0-9]{24}\z/', (string) $response->headers->get('X-Request-ID'));
        self::assertNull($response->headers->get('X-Correlation-ID'));
    }

    public function testItIgnoresNonVersionedApiResponses(): void
    {
        $request = Request::create('/api/live/operations/run-id');
        $response = new Response('ok');

        $this->subscriber()->onKernelResponse($this->responseEvent($request, $response));

        self::assertNull($response->headers->get('X-Request-ID'));
        self::assertNull($response->headers->get('X-Correlation-ID'));
    }

    public function testItIgnoresApiLookalikeResponses(): void
    {
        $request = Request::create('/api/v10/status');
        $response = new Response('ok');

        $this->subscriber()->onKernelResponse($this->responseEvent($request, $response));

        self::assertNull($response->headers->get('X-Request-ID'));
        self::assertNull($response->headers->get('X-Correlation-ID'));
    }

    private function subscriber(): ApiTraceHeaderSubscriber
    {
        return new ApiTraceHeaderSubscriber(new AccessRequestMetadata());
    }

    private function responseEvent(Request $request, Response $response): ResponseEvent
    {
        return new ResponseEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST, $response);
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
