<?php

declare(strict_types=1);

namespace App\Tests\View;

use App\View\SystemPackageMetadataProvider;
use PHPUnit\Framework\TestCase;

final class SystemPackageMetadataProviderTest extends TestCase
{
    public function testItExposesSystemPackageMetadataFromRootManifest(): void
    {
        $projectDir = dirname(__DIR__, 2);
        $manifest = $this->rootManifest($projectDir);
        $metadata = (new SystemPackageMetadataProvider($projectDir))->metadata();

        self::assertSame('system', $metadata['identifier']);
        self::assertSame($manifest['APP_NAME'], $metadata['name']);
        self::assertSame($manifest['APP_AUTHOR'], $metadata['author']);
        self::assertSame($manifest['APP_DESCRIPTION'], $metadata['description']);
        self::assertTrue($metadata['immutable']);
        self::assertTrue($metadata['virtual']);
        self::assertSame(['frontend-theme', 'backend-theme', 'system-template'], $metadata['scopes']);
        self::assertSame($manifest['APP_VERSION'], $metadata['version']);
        self::assertSame($manifest['APP_LICENSE'], $metadata['license']);
        self::assertSame($manifest['APP_HOMEPAGE'], $metadata['homepage']);
        self::assertSame($manifest['APP_SOURCE'], $metadata['source']);
        self::assertSame($manifest['APP_CHANNEL'], $metadata['channel']);
        self::assertArrayHasKey('APP_SOURCE', $metadata['manifest']);
    }

    /**
     * @return array<string, string>
     */
    private function rootManifest(string $projectDir): array
    {
        $manifest = [];
        $lines = file($projectDir.'/.manifest', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $manifest[trim($key)] = trim($value);
        }

        return $manifest;
    }
}
