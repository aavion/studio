<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Core\Package\ActivePackageAssetProviderInterface;
use App\Core\Package\PackageAssetSyncPackage;
use App\Core\Translation\TranslationCatalogueAggregator;
use App\Core\Translation\TranslationCatalogueCacheWarmer;
use App\Core\Translation\TranslationRuntimePath;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class TranslationCatalogueCacheWarmerTest extends TestCase
{
    use FilesystemTestHelper;

    private string $root;

    protected function setUp(): void
    {
        $this->root = $this->createTemporaryDirectory('studio-translation-cache-warmer');
        $this->writeTestFile($this->root, 'translations/languages/en/ui.yaml', "ui:\n  app:\n    name: Studio\n");
        $this->writeTestFile($this->root, 'translations/languages/de/ui.yaml', "ui:\n  app:\n    name: Studio\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testItAggregatesTranslationsAndWritesFreshnessManifest(): void
    {
        $warmer = $this->warmer();

        self::assertSame([], $warmer->warmUp($this->root.'/var/cache/test'));

        self::assertFileExists($this->root.'/translations/runtime/test/messages.en.yaml');
        self::assertFileExists($this->root.'/translations/runtime/test/.manifest.json');

        $manifest = $this->manifest();
        self::assertIsString($manifest['source_hash']);
        self::assertIsString($manifest['generated_hash']);
        self::assertSame([
            'translations/runtime/test/messages.de.yaml',
            'translations/runtime/test/messages.en.yaml',
        ], $manifest['targets']);
    }

    public function testItSkipsWhenSourceAndGeneratedCataloguesAreFresh(): void
    {
        $warmer = $this->warmer();
        $warmer->warmUp($this->root.'/var/cache/test');
        $manifest = $this->manifest();
        $manifest['generated_at'] = 'sentinel';
        file_put_contents($this->root.'/translations/runtime/test/.manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        $warmer->warmUp($this->root.'/var/cache/test');

        self::assertSame('sentinel', $this->manifest()['generated_at']);
    }

    public function testItRegeneratesWhenGeneratedCataloguesDrift(): void
    {
        $warmer = $this->warmer();
        $warmer->warmUp($this->root.'/var/cache/test');
        file_put_contents($this->root.'/translations/runtime/test/messages.en.yaml', "ui:\n  app:\n    name: Stale\n");

        $warmer->warmUp($this->root.'/var/cache/test');

        $english = Yaml::parseFile($this->root.'/translations/runtime/test/messages.en.yaml');
        self::assertSame('Studio', $english['ui']['app']['name']);
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $manifest = json_decode((string) file_get_contents($this->root.'/translations/runtime/test/.manifest.json'), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($manifest);

        return $manifest;
    }

    private function warmer(): TranslationCatalogueCacheWarmer
    {
        $runtimePath = new TranslationRuntimePath($this->root, 'test');

        return new TranslationCatalogueCacheWarmer(
            $this->root,
            new TranslationCatalogueAggregator($this->root, runtimePath: $runtimePath),
            new class implements ActivePackageAssetProviderInterface {
                /**
                 * @return list<PackageAssetSyncPackage>
                 */
                public function packages(): array
                {
                    return [];
                }
            },
            $runtimePath,
        );
    }
}
