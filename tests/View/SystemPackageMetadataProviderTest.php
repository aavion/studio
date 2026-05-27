<?php

declare(strict_types=1);

namespace App\Tests\View;

use App\View\SystemPackageMetadataProvider;
use PHPUnit\Framework\TestCase;

final class SystemPackageMetadataProviderTest extends TestCase
{
    public function testItExposesSystemPackageMetadataFromRootManifest(): void
    {
        $metadata = (new SystemPackageMetadataProvider(dirname(__DIR__, 2)))->metadata();

        self::assertSame('system', $metadata['identifier']);
        self::assertSame('aavion Studio', $metadata['name']);
        self::assertSame('Dominik Letica', $metadata['author']);
        self::assertSame('Symfony 8 based content-management system for structured project websites.', $metadata['description']);
        self::assertTrue($metadata['immutable']);
        self::assertTrue($metadata['virtual']);
        self::assertSame(['frontend-theme', 'backend-theme', 'system-template'], $metadata['scopes']);
        self::assertSame('0.1.0', $metadata['version']);
        self::assertSame('MIT', $metadata['license']);
        self::assertSame('https://www.aavion.media', $metadata['homepage']);
        self::assertSame('https://github.com/aavion/studio', $metadata['source']);
        self::assertSame('dev-latest', $metadata['channel']);
        self::assertArrayHasKey('APP_SOURCE', $metadata['manifest']);
    }
}
