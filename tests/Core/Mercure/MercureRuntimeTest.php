<?php

declare(strict_types=1);

namespace App\Tests\Core\Mercure;

use App\Core\Mercure\MercureBinaryManager;
use App\Core\Mercure\MercureRuntime;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Mercure\HubInterface;
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
}
