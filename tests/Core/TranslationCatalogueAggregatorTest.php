<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Core\Extension\ExtensionAssetSyncTarget;
use App\Core\Extension\ExtensionScope;
use App\Core\Translation\TranslationCatalogueAggregator;
use App\Core\Translation\TranslationCatalogueCollisionException;
use App\Core\Translation\TranslationMessageKey;
use App\Core\Translation\TranslationRuntimePath;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class TranslationCatalogueAggregatorTest extends TestCase
{
    use FilesystemTestHelper;

    private string $root;

    protected function setUp(): void
    {
        $this->root = $this->createTemporaryDirectory('system-extension-translations');
        $this->writeTestFile($this->root, 'translations/languages/en/ui.yaml', "ui:\n  app:\n    name: Studio\n");
        $this->writeTestFile($this->root, 'translations/languages/de/ui.yaml', "ui:\n  app:\n    name: Studio\n");
        $this->writeTestFile($this->root, 'translations/runtime/test/messages.fr.yaml', "stale: true\n");
        $this->writeTestFile($this->root, 'translations/runtime/test/.manifest.json', '{"hash":"previous"}');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testItAggregatesCoreAndActiveExtensionTranslationCatalogues(): void
    {
        $this->writeTestFile($this->root, 'extensions/demo/languages/en/demo.yaml', "ext:\n  demo:\n    label: Demo\n");
        $this->writeTestFile($this->root, 'extensions/demo/languages/de/demo.yaml', "ext:\n  demo:\n    label: Demo\n");
        $this->writeTestFile($this->root, 'extensions/inactive/languages/en/inactive.yaml', "ext:\n  inactive:\n    label: Hidden\n");

        $result = $this->aggregator()->aggregate([
            new ExtensionAssetSyncTarget('demo', 'extensions/demo', [ExtensionScope::Module]),
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame(2, $result->context()['locales']);
        self::assertSame(4, $result->context()['files']);
        self::assertSame(TranslationMessageKey::TRANSLATION_AGGREGATE_COMPLETED, $result->messages()[0]->translationKey());
        self::assertFileExists($this->root.'/translations/runtime/test/messages.en.yaml');
        self::assertFileExists($this->root.'/translations/runtime/test/messages.de.yaml');
        self::assertFileDoesNotExist($this->root.'/translations/runtime/test/messages.fr.yaml');
        self::assertSame('{"hash":"previous"}', file_get_contents($this->root.'/translations/runtime/test/.manifest.json'));

        $english = Yaml::parseFile($this->root.'/translations/runtime/test/messages.en.yaml');
        self::assertSame('Studio', $english['ui']['app']['name']);
        self::assertSame('Demo', $english['ext']['demo']['label']);
        self::assertArrayNotHasKey('inactive', $english['ext']);
    }

    public function testItIgnoresNonExtensionTranslationPaths(): void
    {
        $this->writeTestFile($this->root, 'external/demo/languages/en/demo.yaml', "ext:\n  demo: true\n");

        $result = $this->aggregator()->aggregate([
            new ExtensionAssetSyncTarget('demo', 'external/demo', [ExtensionScope::Module]),
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame(2, $result->context()['files']);
        self::assertFileExists($this->root.'/translations/runtime/test/messages.en.yaml');
    }

    public function testItRejectsTranslationKeyCollisions(): void
    {
        $this->writeTestFile($this->root, 'extensions/demo/languages/en/demo.yaml', "ui:\n  app:\n    name: Override\n");

        $result = $this->aggregator()->aggregate([
            new ExtensionAssetSyncTarget('demo', 'extensions/demo', [ExtensionScope::Module]),
        ]);

        self::assertFalse($result->isSuccess());
        self::assertSame(TranslationMessageKey::TRANSLATION_AGGREGATE_FAILED, $result->firstIssue()?->translationKey());
        self::assertSame(TranslationCatalogueCollisionException::class, $result->context()['exception']);
        self::assertFileExists($this->root.'/translations/runtime/test/messages.fr.yaml');
    }

    public function testItHandlesEmptyCatalogueSources(): void
    {
        $this->removeDirectory($this->root.'/translations/languages');

        $result = $this->aggregator()->aggregate([]);

        self::assertTrue($result->isSuccess());
        self::assertSame(0, $result->context()['locales']);
        self::assertSame(0, $result->context()['files']);
        self::assertSame([], $result->context()['targets']);
        self::assertDirectoryExists($this->root.'/translations/runtime/test');
        self::assertFileDoesNotExist($this->root.'/translations/runtime/test/messages.fr.yaml');
        self::assertSame('{"hash":"previous"}', file_get_contents($this->root.'/translations/runtime/test/.manifest.json'));
    }

    private function aggregator(): TranslationCatalogueAggregator
    {
        return new TranslationCatalogueAggregator($this->root, runtimePath: new TranslationRuntimePath($this->root, 'test'));
    }
}
