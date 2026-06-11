<?php

declare(strict_types=1);

namespace App\Tests\Api\Security;

use App\Api\Http\ApiResponder;
use App\Api\Security\ApiDatabaseExceptionSubscriber;
use App\Api\Security\ApiUnavailableResponder;
use App\Core\Output\JsonOutputRenderer;
use App\Tests\Support\IdentityTranslator;
use Doctrine\DBAL\Exception as DbalException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ApiDatabaseExceptionSubscriberTest extends TestCase
{
    public function testItReturnsServiceUnavailableForApiDbalExceptions(): void
    {
        $error = new class('DB unavailable') extends \Exception implements DbalException {
        };
        $event = $this->event('/api/v1/status', $error);

        $this->subscriber()->onKernelException($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $event->getResponse()->getStatusCode());
        self::assertSame('60', $event->getResponse()->headers->get('Retry-After'));
        $payload = json_decode((string) $event->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('api.unavailable_database', $payload['error']['code']);
        self::assertSame('database_unavailable', $payload['error']['context']['reason']);
        self::assertSame($error::class, $payload['error']['context']['exception']);
    }

    public function testItIgnoresNonApiDbalExceptions(): void
    {
        $event = $this->event('/setup', new class('DB unavailable') extends \Exception implements DbalException {
        });

        $this->subscriber()->onKernelException($event);

        self::assertFalse($event->hasResponse());
    }

    public function testItIgnoresApiNonDbalExceptions(): void
    {
        $event = $this->event('/api/v1/status', new \RuntimeException('Domain bug'));

        $this->subscriber()->onKernelException($event);

        self::assertFalse($event->hasResponse());
    }

    private function subscriber(): ApiDatabaseExceptionSubscriber
    {
        return new ApiDatabaseExceptionSubscriber(
            new ApiUnavailableResponder(new ApiResponder(new JsonOutputRenderer(), new IdentityTranslator())),
        );
    }

    private function event(string $path, \Throwable $error): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->kernel(),
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
            $error,
        );
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
