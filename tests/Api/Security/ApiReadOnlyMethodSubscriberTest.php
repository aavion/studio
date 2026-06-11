<?php

declare(strict_types=1);

namespace App\Tests\Api\Security;

use App\Api\Http\ApiRequestContext;
use App\Api\Http\ApiResponder;
use App\Api\Security\ApiReadOnlyMethodSubscriber;
use App\Core\Output\JsonOutputRenderer;
use App\Entity\ApiKey;
use App\Entity\UserAccount;
use App\Security\ApiKeyStatus;
use App\Tests\Support\IdentityTranslator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ApiReadOnlyMethodSubscriberTest extends TestCase
{
    public function testItBlocksMutatingRequestsForReadOnlyKeys(): void
    {
        $request = Request::create('/api/v1/status', 'POST');
        $this->context(ApiKeyStatus::ReadOnly)->attachTo($request);
        $event = new RequestEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber()->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_FORBIDDEN, $event->getResponse()->getStatusCode());
        $payload = json_decode((string) $event->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('api_key.permission_write_required', $payload['error']['code']);
        self::assertSame('POST', $payload['error']['context']['method']);
    }

    public function testItAllowsSafeRequestsForReadOnlyKeys(): void
    {
        $request = Request::create('/api/v1/status', 'GET');
        $this->context(ApiKeyStatus::ReadOnly)->attachTo($request);
        $event = new RequestEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber()->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testItAllowsMutatingRequestsForReadWriteKeys(): void
    {
        $request = Request::create('/api/v1/status', 'POST');
        $this->context(ApiKeyStatus::ReadWrite)->attachTo($request);
        $event = new RequestEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber()->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testItPreservesEarlierApiResponses(): void
    {
        $request = Request::create('/api/v1/status', 'POST');
        $this->context(ApiKeyStatus::ReadOnly)->attachTo($request);
        $event = new RequestEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST);
        $event->setResponse(new Response('Unsupported media type', Response::HTTP_UNSUPPORTED_MEDIA_TYPE));

        $this->subscriber()->onKernelRequest($event);

        self::assertSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, $event->getResponse()->getStatusCode());
        self::assertSame('Unsupported media type', $event->getResponse()->getContent());
    }

    private function subscriber(): ApiReadOnlyMethodSubscriber
    {
        return new ApiReadOnlyMethodSubscriber(new ApiResponder(
            new JsonOutputRenderer(),
            new IdentityTranslator(),
        ));
    }

    private function context(ApiKeyStatus $status): ApiRequestContext
    {
        $user = new UserAccount(
            '65000000-0000-7000-8000-000000000001',
            'apitester',
            'apitester@example.test',
            'hash',
        );

        return ApiRequestContext::fromApiKey(new ApiKey(
            '65000000-0000-7000-8000-000000000002',
            'apitest',
            str_repeat('a', 64),
            'encrypted',
            $user,
            $status,
        ));
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
