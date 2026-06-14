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
    public function testItStartsLocalHubWithAnonymousSubscribersEnabled(): void
    {
        $root = sys_get_temp_dir().'/studio-mercure-runtime-test-'.bin2hex(random_bytes(4));
        $runtime = new MercureRuntime(
            new MercureBinaryManager($root),
            $this->hub(),
            'http://127.0.0.1:8000',
            $root,
        );

        try {
            self::assertSame([
                $root.'/var/mercure/0.24.2/mercure',
                'run',
                '--envfile',
                $root.'/var/mercure/mercure.env',
                '--config',
                $root.'/var/mercure/0.24.2/Caddyfile',
                '--adapter',
                'caddyfile',
            ], $runtime->startCommand());
            self::assertNotContains('--publisher-jwt-key', $runtime->startCommand());
            self::assertNotContains('--subscriber-jwt-key', $runtime->startCommand());
            self::assertArrayNotHasKey('SERVER_NAME', $runtime->startEnvironment());
            self::assertArrayNotHasKey('MERCURE_PUBLISHER_JWT_KEY', $runtime->startEnvironment());
            self::assertArrayNotHasKey('MERCURE_SUBSCRIBER_JWT_KEY', $runtime->startEnvironment());
            self::assertStringContainsString('anonymous', $runtime->startEnvironment()['MERCURE_EXTRA_DIRECTIVES']);
            self::assertStringContainsString('cors_origins *', $runtime->startEnvironment()['MERCURE_EXTRA_DIRECTIVES']);
            self::assertStringContainsString('path "'.$root.'/var/mercure/updates.db"', $runtime->startEnvironment()['MERCURE_EXTRA_DIRECTIVES']);
            self::assertStringContainsString('SERVER_NAME=http://127.0.0.1:3000', (string) file_get_contents($root.'/var/mercure/mercure.env'));
            self::assertSame('0600', substr(sprintf('%o', fileperms($root.'/var/mercure/mercure.env')), -4));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testItResolvesCurrentCaddyBasedReleaseAssetNames(): void
    {
        $manager = new MercureBinaryManager('/tmp/studio');
        $method = new ReflectionMethod(MercureBinaryManager::class, 'assetName');
        $asset = $method->invoke($manager);

        self::assertIsString($asset);
        self::assertStringStartsWith('mercure_', $asset);
        self::assertStringNotContainsString('legacy', $asset);
    }

    public function testItMapsSupportedHostPlatformsToReleaseAssetNames(): void
    {
        $method = new ReflectionMethod(MercureBinaryManager::class, 'assetNameFor');

        $expectedAssets = [
            ['Darwin', 'arm64', 'mercure_Darwin_arm64.tar.gz'],
            ['Darwin', 'x86_64', 'mercure_Darwin_x86_64.tar.gz'],
            ['Darwin', 'amd64', 'mercure_Darwin_x86_64.tar.gz'],
            ['Linux', 'aarch64', 'mercure_Linux_arm64.tar.gz'],
            ['Linux', 'arm64', 'mercure_Linux_arm64.tar.gz'],
            ['Linux', 'armv5', 'mercure_Linux_armv5.tar.gz'],
            ['Linux', 'armv5l', 'mercure_Linux_armv5.tar.gz'],
            ['Linux', 'armv6', 'mercure_Linux_armv6.tar.gz'],
            ['Linux', 'armv6l', 'mercure_Linux_armv6.tar.gz'],
            ['Linux', 'armv7', 'mercure_Linux_armv7.tar.gz'],
            ['Linux', 'armv7l', 'mercure_Linux_armv7.tar.gz'],
            ['Linux', 'i386', 'mercure_Linux_i386.tar.gz'],
            ['Linux', 'i686', 'mercure_Linux_i386.tar.gz'],
            ['Linux', 'x86_64', 'mercure_Linux_x86_64.tar.gz'],
            ['Linux', 'amd64', 'mercure_Linux_x86_64.tar.gz'],
            ['Windows', 'arm64', 'mercure_Windows_arm64.zip'],
            ['Windows', 'i386', 'mercure_Windows_i386.zip'],
            ['Windows', 'i686', 'mercure_Windows_i386.zip'],
            ['Windows', 'x86_64', 'mercure_Windows_x86_64.zip'],
            ['Windows', 'amd64', 'mercure_Windows_x86_64.zip'],
        ];

        foreach ($expectedAssets as [$osFamily, $machine, $asset]) {
            self::assertSame($asset, $method->invoke(null, $osFamily, $machine), $osFamily.' '.$machine);
        }

        self::assertNull($method->invoke(null, 'FreeBSD', 'x86_64'));
        self::assertNull($method->invoke(null, 'Linux', 'riscv64'));
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

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
