<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\ExtensionDiscovery;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;

final class ExtensionDiscoveryTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = $this->createTemporaryDirectory('system-extension-discovery');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testItDiscoversDefaultExtensionLocations(): void
    {
        $this->writeManifest('.', <<<'MANIFEST'
            APP_VERSION=0.1.0
            APP_DATE=2026-05-22
            APP_CHANNEL=dev-latest
            APP_SOURCE=https://github.com/aavion/studio.git
            MANIFEST);
        $this->writeManifest('extensions/system-frontend', <<<'MANIFEST'
            EXTENSION_AUTHOR=Aavion
            EXTENSION_SLUG=system-frontend
            EXTENSION_NAME=System Frontend
            EXTENSION_VERSION=1.0.0
            EXTENSION_SCOPE=frontend-theme
            EXTENSION_DEPENDENCIES=[]
            MANIFEST);
        $this->writeManifest('extensions/contact', <<<'MANIFEST'
            EXTENSION_AUTHOR=Aavion
            EXTENSION_SLUG=contact
            EXTENSION_NAME=Contact
            EXTENSION_VERSION=1.0.0
            EXTENSION_SCOPE=[module, captcha-provider]
            EXTENSION_DEPENDENCIES=[]
            MANIFEST);
        $this->writeManifest('var/cache/test/imports/theme-update', <<<'MANIFEST'
            EXTENSION_NAME=Theme Update
            EXTENSION_VERSION=1.0.1
            MANIFEST);

        $result = (new ExtensionDiscovery())->discover($this->projectDir, 'test');

        self::assertTrue($result->isSuccess());
        self::assertCount(4, $result->value());
        self::assertSame(['app', 'extension', 'extension', 'import'], array_map(
            static fn ($candidate): string => $candidate->source()->name(),
            $result->value(),
        ));
        self::assertSame('Contact', $result->value()[1]->manifest()->get('EXTENSION_NAME'));
        self::assertSame('System Frontend', $result->value()[2]->manifest()->get('EXTENSION_NAME'));
    }

    public function testItTreatsMissingExtensionDirectoriesAsEmptySources(): void
    {
        $this->writeManifest('.', 'APP_VERSION=0.1.0');

        $result = (new ExtensionDiscovery())->discover($this->projectDir, 'test');

        self::assertTrue($result->isSuccess());
        self::assertCount(1, $result->value());
        self::assertSame('app', $result->value()[0]->source()->name());
    }

    public function testItReportsInvalidExtensionManifests(): void
    {
        $this->writeManifest('.', 'APP_VERSION=0.1.0');
        $this->writeManifest('extensions/broken', <<<'MANIFEST'
            EXTENSION_SLUG=broken
            EXTENSION_NAME=Broken
            EXTENSION_SCOPE=unsupported-scope
            EXTENSION_DEPENDENCIES=[]
            EXTENSION_UNDECLARED=value
            MANIFEST);

        $result = (new ExtensionDiscovery())->discover($this->projectDir, 'test');

        self::assertFalse($result->isSuccess());
        self::assertCount(2, $result->issues());
        self::assertSame('manifest.missing_required_key', $result->issues()[0]->code());
        self::assertSame('EXTENSION_AUTHOR', $result->issues()[0]->context()['key']);
        self::assertSame('manifest.missing_required_key', $result->issues()[1]->code());
        self::assertSame('EXTENSION_VERSION', $result->issues()[1]->context()['key']);
    }

    public function testItReportsInvalidExtensionScopes(): void
    {
        $this->writeManifest('.', 'APP_VERSION=0.1.0');
        $this->writeManifest('extensions/broken-scope', <<<'MANIFEST'
            EXTENSION_AUTHOR=Aavion
            EXTENSION_SLUG=broken-scope
            EXTENSION_NAME=Broken Scope
            EXTENSION_VERSION=1.0.0
            EXTENSION_SCOPE=frontend-theme,unsupported-scope
            EXTENSION_DEPENDENCIES=[]
            MANIFEST);

        $result = (new ExtensionDiscovery())->discover($this->projectDir, 'test');

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.scope_invalid', $result->firstIssue()?->code());
        self::assertSame('frontend-theme,unsupported-scope', $result->firstIssue()?->context()['scope']);
    }

    public function testItReportsCapabilityOnlyExtensionScopes(): void
    {
        $this->writeManifest('.', 'APP_VERSION=0.1.0');
        $this->writeManifest('extensions/capability-only', <<<'MANIFEST'
            EXTENSION_AUTHOR=Aavion
            EXTENSION_SLUG=capability-only
            EXTENSION_NAME=Capability Only
            EXTENSION_VERSION=1.0.0
            EXTENSION_SCOPE=[system-template, api, database]
            EXTENSION_DEPENDENCIES=[]
            MANIFEST);

        $result = (new ExtensionDiscovery())->discover($this->projectDir, 'test');

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.scope_invalid', $result->firstIssue()?->code());
        self::assertSame('[system-template, api, database]', $result->firstIssue()?->context()['scope']);
    }

    public function testImportManifestsAreParsedWithoutNamespaceRestrictions(): void
    {
        $this->writeManifest('.', 'APP_VERSION=0.1.0');
        $this->writeManifest('var/cache/test/imports/any-extension', <<<'MANIFEST'
            CUSTOM_NAME=Any Extension
            CUSTOM_VERSION=1.0.0
            MANIFEST);

        $result = (new ExtensionDiscovery())->discover($this->projectDir, 'test');

        self::assertTrue($result->isSuccess());
        self::assertCount(2, $result->value());
        self::assertSame('import', $result->value()[1]->source()->name());
        self::assertSame('Any Extension', $result->value()[1]->manifest()->get('CUSTOM_NAME'));
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
