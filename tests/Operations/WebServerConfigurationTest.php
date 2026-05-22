<?php

declare(strict_types=1);

namespace App\Tests\Operations;

use PHPUnit\Framework\TestCase;

final class WebServerConfigurationTest extends TestCase
{
    public function testApacheFallbackHtaccessAvoidsBroadOverrideDirectives(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/public/.htaccess');

        self::assertIsString($contents);
        self::assertStringContainsString('RewriteEngine On', $contents);
        self::assertStringContainsString('E=BASE:%1', $contents);
        self::assertStringContainsString('%{ENV:BASE}/$1', $contents);
        self::assertStringContainsString('%{ENV:BASE}/index.php', $contents);
        self::assertStringNotContainsString('Options ', $contents);
        self::assertStringNotContainsString('DirectoryIndex', $contents);
    }

    public function testWebServerTemplatesExist(): void
    {
        $root = dirname(__DIR__, 2);

        self::assertFileExists($root.'/config/webserver/apache-vhost.conf');
        self::assertFileExists($root.'/config/webserver/nginx.conf');
        self::assertFileExists($root.'/public/web.config');
        self::assertFileExists($root.'/dev/manual/web-server-configuration.md');
    }

    public function testIisFrontControllerRedirectPreservesBasePath(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/public/web.config');

        self::assertIsString($contents);
        self::assertStringContainsString('input="{REQUEST_URI}" pattern="^(.*/)index\.php/?(.*)$"', $contents);
        self::assertStringContainsString('url="{C:1}{C:2}"', $contents);
        self::assertStringNotContainsString('url="/{R:1}"', $contents);
    }
}
