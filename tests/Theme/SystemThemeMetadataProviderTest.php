<?php

declare(strict_types=1);

namespace App\Tests\Theme;

use App\Theme\SystemThemeMetadataProvider;
use PHPUnit\Framework\TestCase;

final class SystemThemeMetadataProviderTest extends TestCase
{
    public function testItExposesSystemThemeMetadataFromRootManifest(): void
    {
        $metadata = (new SystemThemeMetadataProvider(dirname(__DIR__, 2)))->metadata();

        self::assertSame('system', $metadata['identifier']);
        self::assertSame('System', $metadata['name']);
        self::assertTrue($metadata['immutable']);
        self::assertTrue($metadata['public_fallback']);
        self::assertSame('0.0.0', $metadata['version']);
        self::assertArrayHasKey('APP_SOURCE', $metadata['manifest']);
    }
}
