<?php

declare(strict_types=1);

namespace App\Tests\Core\Mercure;

use App\Core\Mercure\MercureBinaryManager;
use App\Core\Mercure\MercureRuntime;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

final class MercureRuntimeTest extends TestCase
{
    public function testItBuildsBoltTransportUrlsForUnixAndWindowsPaths(): void
    {
        $method = new ReflectionMethod(MercureRuntime::class, 'boltTransportUrl');
        $runtime = new MercureRuntime(
            new MercureBinaryManager('/tmp/studio'),
            $this->hub(),
            'http://127.0.0.1:8000',
            '/tmp/studio',
        );

        self::assertSame(
            'bolt:///var/www/studio/var/mercure/updates.db?size=1000&cleanup_frequency=0.3',
            $method->invoke($runtime, '/var/www/studio/var/mercure/updates.db'),
        );
        self::assertSame(
            'bolt:///C:/studio/var/mercure/updates.db?size=1000&cleanup_frequency=0.3',
            $method->invoke($runtime, 'C:\\studio\\var\\mercure\\updates.db'),
        );
    }

    public function testItStartsLocalHubWithAnonymousSubscribersEnabled(): void
    {
        $runtime = new MercureRuntime(
            new MercureBinaryManager('/tmp/studio'),
            $this->hub(),
            'http://127.0.0.1:8000',
            '/tmp/studio',
        );

        self::assertContains('--allow-anonymous', $runtime->startCommand());
        self::assertNotContains('--publisher-jwt-key', $runtime->startCommand());
        self::assertNotContains('--subscriber-jwt-key', $runtime->startCommand());
        self::assertArrayHasKey('MERCURE_PUBLISHER_JWT_KEY', $runtime->startEnvironment());
        self::assertArrayHasKey('MERCURE_SUBSCRIBER_JWT_KEY', $runtime->startEnvironment());
    }

    public function testItAcceptsReachabilityProbeStatusCodes(): void
    {
        $method = new ReflectionMethod(MercureRuntime::class, 'probeStatusAccepted');

        foreach ([200, 201, 204, 400, 401] as $status) {
            self::assertTrue($method->invoke(null, $status), sprintf('Status %d should be accepted.', $status));
        }

        foreach ([0, 301, 403, 404, 500] as $status) {
            self::assertFalse($method->invoke(null, $status), sprintf('Status %d should not be accepted.', $status));
        }
    }

    public function testPublishHealthProbeRequiresSuccessfulPublishResponse(): void
    {
        foreach ([200, 201, 204] as $status) {
            $runtime = new MercureRuntime(
                new MercureBinaryManager('/tmp/studio'),
                $this->hubWithProvider(),
                'http://127.0.0.1:8000',
                '/tmp/studio',
                new MockHttpClient(static function (string $method, string $url, array $options = []) use ($status): MockResponse {
                    self::assertSame('POST', $method);
                    self::assertStringContainsString('/.well-known/mercure', $url);

                    return new MockResponse('', ['http_code' => $status]);
                }),
            );

            self::assertTrue($runtime->publishHealthProbe(), sprintf('Status %d should make the publish endpoint functional.', $status));
        }

        foreach ([400, 401, 403, 500] as $status) {
            $runtime = new MercureRuntime(
                new MercureBinaryManager('/tmp/studio'),
                $this->hubWithProvider(),
                'http://127.0.0.1:8000',
                '/tmp/studio',
                new MockHttpClient(new MockResponse('', ['http_code' => $status])),
            );

            self::assertFalse($runtime->publishHealthProbe(), sprintf('Status %d should not make the publish endpoint functional.', $status));
        }
    }

    private function hub(): HubInterface
    {
        return new class implements HubInterface {
            public function getPublicUrl(): string
            {
                return 'http://127.0.0.1:3000/.well-known/mercure';
            }

            public function getFactory(): ?TokenFactoryInterface
            {
                return null;
            }

            public function publish(Update $update): string
            {
                return 'test';
            }
        };
    }

    private function hubWithProvider(): HubInterface
    {
        return new class implements HubInterface {
            public function getProvider(): StaticTokenProvider
            {
                return new StaticTokenProvider('jwt');
            }

            public function getPublicUrl(): string
            {
                return 'http://127.0.0.1:3000/.well-known/mercure';
            }

            public function getFactory(): ?TokenFactoryInterface
            {
                return null;
            }

            public function publish(Update $update): string
            {
                return 'test';
            }
        };
    }
}
