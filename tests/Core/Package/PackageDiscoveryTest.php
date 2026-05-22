<?php

declare(strict_types=1);

namespace App\Tests\Core\Package;

use App\Core\Package\PackageDiscovery;
use PHPUnit\Framework\TestCase;

final class PackageDiscoveryTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/studio-package-discovery-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testItDiscoversDefaultPackageLocations(): void
    {
        $this->writeManifest('.', <<<'MANIFEST'
            APP_VERSION=0.1.0
            APP_DATE=2026-05-22
            APP_CHANNEL=dev-latest
            APP_SOURCE=https://github.com/aavion/studio.git
            MANIFEST);
        $this->writeManifest('themes/system', <<<'MANIFEST'
            THEME_NAME=System
            THEME_VERSION=1.0.0
            THEME_AUTHOR=Aavion
            MANIFEST);
        $this->writeManifest('modules/contact', <<<'MANIFEST'
            MODULE_NAME=Contact
            MODULE_VERSION=1.0.0
            MODULE_AUTHOR=Aavion
            MANIFEST);
        $this->writeManifest('var/cache/test/imports/theme-update', <<<'MANIFEST'
            PACKAGE_NAME=Theme Update
            PACKAGE_VERSION=1.0.1
            MANIFEST);

        $result = (new PackageDiscovery())->discover($this->projectDir, 'test');

        self::assertTrue($result->isSuccess());
        self::assertCount(4, $result->value());
        self::assertSame(['app', 'theme', 'module', 'import'], array_map(
            static fn ($candidate): string => $candidate->source()->name(),
            $result->value(),
        ));
        self::assertSame('System', $result->value()[1]->manifest()->get('THEME_NAME'));
    }

    public function testItTreatsMissingPackageDirectoriesAsEmptySources(): void
    {
        $this->writeManifest('.', 'APP_VERSION=0.1.0');

        $result = (new PackageDiscovery())->discover($this->projectDir, 'test');

        self::assertTrue($result->isSuccess());
        self::assertCount(1, $result->value());
        self::assertSame('app', $result->value()[0]->source()->name());
    }

    public function testItReportsInvalidNamespacedThemeManifests(): void
    {
        $this->writeManifest('.', 'APP_VERSION=0.1.0');
        $this->writeManifest('themes/broken', <<<'MANIFEST'
            THEME_NAME=Broken
            THEME_UNDECLARED=value
            MANIFEST);

        $result = (new PackageDiscovery())->discover($this->projectDir, 'test');

        self::assertFalse($result->isSuccess());
        self::assertCount(2, $result->issues());
        self::assertSame('manifest.missing_required_key', $result->issues()[0]->code());
        self::assertSame('THEME_VERSION', $result->issues()[0]->context()['key']);
        self::assertSame('manifest.unknown_key', $result->issues()[1]->code());
        self::assertSame('theme', $result->issues()[1]->context()['source']);
    }

    public function testImportManifestsAreParsedWithoutNamespaceRestrictions(): void
    {
        $this->writeManifest('.', 'APP_VERSION=0.1.0');
        $this->writeManifest('var/cache/test/imports/any-package', <<<'MANIFEST'
            CUSTOM_NAME=Any Package
            CUSTOM_VERSION=1.0.0
            MANIFEST);

        $result = (new PackageDiscovery())->discover($this->projectDir, 'test');

        self::assertTrue($result->isSuccess());
        self::assertCount(2, $result->value());
        self::assertSame('import', $result->value()[1]->source()->name());
        self::assertSame('Any Package', $result->value()[1]->manifest()->get('CUSTOM_NAME'));
    }

    private function writeManifest(string $relativeDirectory, string $contents): void
    {
        $directory = $this->projectDir.'/'.trim($relativeDirectory, '/');
        if ('.' === $relativeDirectory) {
            $directory = $this->projectDir;
        }

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($directory.'/.manifest', $contents);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        if (false === $entries) {
            return;
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
