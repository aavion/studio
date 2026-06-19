<?php

declare(strict_types=1);

namespace App\Tests\Core\Package;

use App\Core\Package\PackageDiscovery;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;

final class PackageDiscoveryTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = $this->createTemporaryDirectory('system-package-discovery');
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
        $this->writeManifest('packages/system-frontend', <<<'MANIFEST'
            PACKAGE_AUTHOR=Aavion
            PACKAGE_SLUG=system-frontend
            PACKAGE_NAME=System Frontend
            PACKAGE_VERSION=1.0.0
            PACKAGE_SCOPE=frontend-theme
            PACKAGE_DEPENDENCIES=[]
            MANIFEST);
        $this->writeManifest('packages/contact', <<<'MANIFEST'
            PACKAGE_AUTHOR=Aavion
            PACKAGE_SLUG=contact
            PACKAGE_NAME=Contact
            PACKAGE_VERSION=1.0.0
            PACKAGE_SCOPE=[module, captcha-provider]
            PACKAGE_DEPENDENCIES=[]
            MANIFEST);
        $this->writeManifest('var/cache/test/imports/theme-update', <<<'MANIFEST'
            PACKAGE_NAME=Theme Update
            PACKAGE_VERSION=1.0.1
            MANIFEST);

        $result = (new PackageDiscovery())->discover($this->projectDir, 'test');

        self::assertTrue($result->isSuccess());
        self::assertCount(4, $result->value());
        self::assertSame(['app', 'package', 'package', 'import'], array_map(
            static fn ($candidate): string => $candidate->source()->name(),
            $result->value(),
        ));
        self::assertSame('Contact', $result->value()[1]->manifest()->get('PACKAGE_NAME'));
        self::assertSame('System Frontend', $result->value()[2]->manifest()->get('PACKAGE_NAME'));
    }

    public function testItTreatsMissingPackageDirectoriesAsEmptySources(): void
    {
        $this->writeManifest('.', 'APP_VERSION=0.1.0');

        $result = (new PackageDiscovery())->discover($this->projectDir, 'test');

        self::assertTrue($result->isSuccess());
        self::assertCount(1, $result->value());
        self::assertSame('app', $result->value()[0]->source()->name());
    }

    public function testItReportsInvalidPackageManifests(): void
    {
        $this->writeManifest('.', 'APP_VERSION=0.1.0');
        $this->writeManifest('packages/broken', <<<'MANIFEST'
            PACKAGE_SLUG=broken
            PACKAGE_NAME=Broken
            PACKAGE_SCOPE=unsupported-scope
            PACKAGE_DEPENDENCIES=[]
            PACKAGE_UNDECLARED=value
            MANIFEST);

        $result = (new PackageDiscovery())->discover($this->projectDir, 'test');

        self::assertFalse($result->isSuccess());
        self::assertCount(2, $result->issues());
        self::assertSame('manifest.missing_required_key', $result->issues()[0]->code());
        self::assertSame('PACKAGE_AUTHOR', $result->issues()[0]->context()['key']);
        self::assertSame('manifest.missing_required_key', $result->issues()[1]->code());
        self::assertSame('PACKAGE_VERSION', $result->issues()[1]->context()['key']);
    }

    public function testItReportsInvalidPackageScopes(): void
    {
        $this->writeManifest('.', 'APP_VERSION=0.1.0');
        $this->writeManifest('packages/broken-scope', <<<'MANIFEST'
            PACKAGE_AUTHOR=Aavion
            PACKAGE_SLUG=broken-scope
            PACKAGE_NAME=Broken Scope
            PACKAGE_VERSION=1.0.0
            PACKAGE_SCOPE=frontend-theme,unsupported-scope
            PACKAGE_DEPENDENCIES=[]
            MANIFEST);

        $result = (new PackageDiscovery())->discover($this->projectDir, 'test');

        self::assertFalse($result->isSuccess());
        self::assertSame('package.scope_invalid', $result->firstIssue()?->code());
        self::assertSame('frontend-theme,unsupported-scope', $result->firstIssue()?->context()['scope']);
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

        $this->writeTestFile($directory, '.manifest', $contents);
    }
}
