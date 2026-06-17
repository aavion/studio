<?php

declare(strict_types=1);

namespace App\Tests\Api\Security;

use App\Api\ApiFeaturePolicy;
use App\Api\Security\ApiCorsSubscriber;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ApiCorsSubscriberTest extends TestCase
{
    public function testItAnswersAllowedApiPreflightRequests(): void
    {
        $event = $this->requestEvent(Request::create('/api/v1/status', 'OPTIONS', server: [
            'HTTP_ORIGIN' => 'https://client.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]));

        $this->subscriber(['https://client.example'])->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_NO_CONTENT, $event->getResponse()->getStatusCode());
        self::assertSame('https://client.example', $event->getResponse()->headers->get('Access-Control-Allow-Origin'));
        self::assertStringContainsString('Authorization', (string) $event->getResponse()->headers->get('Access-Control-Allow-Headers'));
        self::assertSame('X-Request-ID, X-Correlation-ID', $event->getResponse()->headers->get('Access-Control-Expose-Headers'));
        self::assertSame('Origin', $event->getResponse()->headers->get('Vary'));
    }

    public function testItIgnoresDisallowedOrigins(): void
    {
        $event = $this->requestEvent(Request::create('/api/v1/status', 'OPTIONS', server: [
            'HTTP_ORIGIN' => 'https://unknown.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]));

        $this->subscriber(['https://client.example'])->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testItDoesNotShortCircuitPreflightsWithActualAuthorizationHeader(): void
    {
        $event = $this->requestEvent(Request::create('/api/v1/admin/settings/general', 'OPTIONS', server: [
            'HTTP_ORIGIN' => 'https://client.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
            'HTTP_AUTHORIZATION' => 'Basic unrelated',
        ]));

        $this->subscriber(['https://client.example'])->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testItAddsCorsHeadersToAllowedApiResponses(): void
    {
        $request = Request::create('/api/v1/status', 'GET', server: [
            'HTTP_ORIGIN' => 'https://client.example',
        ]);
        $response = new Response('ok');
        $event = new ResponseEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $this->subscriber(['https://client.example'])->onKernelResponse($event);

        self::assertSame('https://client.example', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('X-Request-ID, X-Correlation-ID', $response->headers->get('Access-Control-Expose-Headers'));
        self::assertSame('Origin', $response->headers->get('Vary'));
    }

    public function testItSupportsIntentionalWildcardOrigins(): void
    {
        $request = Request::create('/api/v1/status', 'GET', server: [
            'HTTP_ORIGIN' => 'https://client.example',
        ]);
        $response = new Response('ok');
        $event = new ResponseEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $this->subscriber(['*'])->onKernelResponse($event);

        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertNull($response->headers->get('Vary'));
    }

    public function testItDoesNothingWhenCorsIsDisabled(): void
    {
        $request = Request::create('/api/v1/status', 'GET', server: [
            'HTTP_ORIGIN' => 'https://client.example',
        ]);
        $response = new Response('ok');
        $event = new ResponseEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $this->subscriber(['https://client.example'], enabled: false)->onKernelResponse($event);

        self::assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    /**
     * @param list<string> $origins
     */
    private function subscriber(array $origins, bool $enabled = true): ApiCorsSubscriber
    {
        $config = $this->config();
        $config->set(ApiFeaturePolicy::CORS_ENABLED_KEY, $enabled, ConfigValueType::Boolean);
        $config->set(ApiFeaturePolicy::CORS_ALLOWED_ORIGINS_KEY, $origins, ConfigValueType::Json);

        return new ApiCorsSubscriber(new ApiFeaturePolicy($config));
    }

    private function config(): Config
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL, modified_at DATETIME NOT NULL, modified_by VARCHAR(180) DEFAULT NULL)');

        return new Config($connection);
    }

    private function requestEvent(Request $request): RequestEvent
    {
        return new RequestEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST);
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
