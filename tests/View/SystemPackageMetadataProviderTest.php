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
        self::assertSame('System', $metadata['name']);
        self::assertTrue($metadata['immutable']);
        self::assertTrue($metadata['virtual']);
        self::assertSame(['frontend-theme', 'backend-theme', 'system-template'], $metadata['scopes']);
        self::assertSame('0.0.0', $metadata['version']);
        self::assertArrayHasKey('APP_SOURCE', $metadata['manifest']);
    }
}
