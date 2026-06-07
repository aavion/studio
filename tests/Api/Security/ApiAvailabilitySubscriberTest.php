<?php

declare(strict_types=1);

namespace App\Tests\Api\Security;

use App\Api\ApiFeaturePolicy;
use App\Api\Http\ApiResponder;
use App\Api\Security\ApiAvailabilityCheckerInterface;
use App\Api\Security\ApiAvailabilitySubscriber;
use App\Api\Security\ApiUnavailableResponder;
use App\Core\Config\Config;
use App\Core\Output\JsonOutputRenderer;
use App\Tests\Support\IdentityTranslator;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ApiAvailabilitySubscriberTest extends TestCase
{
    public function testItReturnsServiceUnavailableWhenSetupIsIncomplete(): void
    {
        $event = $this->event('/api/v1/status');

        $this->subscriber(new class implements ApiAvailabilityCheckerInterface {
            public function isAvailable(): bool
            {
                return false;
            }
        })->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $event->getResponse()->getStatusCode());
        self::assertSame('60', $event->getResponse()->headers->get('Retry-After'));
        $payload = json_decode((string) $event->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('api.unavailable_setup_incomplete', $payload['error']['code']);
        self::assertSame('setup_incomplete', $payload['error']['context']['reason']);
    }

    public function testItReturnsServiceUnavailableWhenApiIsDisabled(): void
    {
        $event = $this->event('/api/v1/status');

        $this->subscriber(new class implements ApiAvailabilityCheckerInterface {
            public function isAvailable(): bool
            {
                return true;
            }
        }, enabled: false)->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $event->getResponse()->getStatusCode());
        self::assertSame('60', $event->getResponse()->headers->get('Retry-After'));
        $payload = json_decode((string) $event->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('api.unavailable_disabled', $payload['error']['code']);
        self::assertSame('api_disabled', $payload['error']['context']['reason']);
    }

    public function testItReturnsServiceUnavailableWhenDatabaseReadyCheckThrows(): void
    {
        $event = $this->event('/api/v1/status');

        $this->subscriber(new class implements ApiAvailabilityCheckerInterface {
            public function isAvailable(): bool
            {
                throw new \RuntimeException('DB unavailable');
            }
        })->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $event->getResponse()->getStatusCode());
        $payload = json_decode((string) $event->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('api.unavailable_database', $payload['error']['code']);
        self::assertSame('database_unavailable', $payload['error']['context']['reason']);
        self::assertSame(\RuntimeException::class, $payload['error']['context']['exception']);
    }

    public function testItIgnoresNonApiRequests(): void
    {
        $event = $this->event('/setup');

        $this->subscriber(new class implements ApiAvailabilityCheckerInterface {
            public function isAvailable(): bool
            {
                return false;
            }
        })->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    private function subscriber(ApiAvailabilityCheckerInterface $availabilityChecker, bool $enabled = true): ApiAvailabilitySubscriber
    {
        $config = $this->config();
        $config->set(ApiFeaturePolicy::ENABLED_KEY, $enabled);

        return new ApiAvailabilitySubscriber(
            $availabilityChecker,
            new ApiUnavailableResponder(new ApiResponder(new JsonOutputRenderer(), new IdentityTranslator())),
            new ApiFeaturePolicy($config),
        );
    }

    private function config(): Config
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL, modified_at DATETIME NOT NULL, modified_by VARCHAR(180) DEFAULT NULL)');

        return new Config($connection);
    }

    private function event(string $path): RequestEvent
    {
        return new RequestEvent(
            $this->kernel(),
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
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
