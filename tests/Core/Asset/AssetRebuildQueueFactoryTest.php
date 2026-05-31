<?php

declare(strict_types=1);

namespace App\Tests\Core\Asset;

use App\Core\Asset\AssetRebuildQueueFactory;
use App\Core\Package\PackageAssetSyncPackage;
use App\Core\Package\PackageAssetSyncer;
use App\Core\Package\PackageScope;
use App\Core\Translation\TranslationCatalogueAggregator;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;

final class AssetRebuildQueueFactoryTest extends TestCase
{
    use FilesystemTestHelper;

    private string $root;

    protected function setUp(): void
    {
        $this->root = $this->createTemporaryDirectory('studio-asset-rebuild');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testItBuildsDevelopmentRebuildQueueWithCacheClearAsFinalAction(): void
    {
        $queue = $this->factory()->create('dev', [
            new PackageAssetSyncPackage('demo', 'packages/demo', [PackageScope::Module]),
        ]);
        $actions = $queue->actions();

        self::assertCount(6, $actions);
        self::assertSame('package_asset_sync', $actions[0]->type());
        self::assertSame('translation_aggregate', $actions[1]->type());
        self::assertStringContainsString('assets:install', $actions[2]->label());
        self::assertStringContainsString('importmap:install', $actions[3]->label());
        self::assertStringContainsString('tailwind:build', $actions[4]->label());
        self::assertStringContainsString('cache:clear', $actions[5]->label());
        self::assertFalse($queue->context()['production_compile']);
        self::assertSame('manual', $queue->context()['trigger']);
        self::assertSame(1, count(array_filter(
            $actions,
            static fn (object $action): bool => method_exists($action, 'type') && 'translation_aggregate' === $action->type(),
        )));
    }

    public function testItAddsProductionAssetMapCompileAfterRemovingCompiledAssets(): void
    {
        $queue = $this->factory()->create('prod', [], 'setup');
        $actions = $queue->actions();

        self::assertCount(8, $actions);
        self::assertSame('remove_path', $actions[5]->type());
        self::assertStringContainsString('public/assets', $actions[5]->label());
        self::assertStringContainsString('asset-map:compile', $actions[6]->label());
        self::assertStringContainsString('cache:clear', $actions[7]->label());
        self::assertTrue($queue->context()['production_compile']);
        self::assertSame('setup', $queue->context()['trigger']);
    }

    private function factory(): AssetRebuildQueueFactory
    {
        return new AssetRebuildQueueFactory($this->root, new PackageAssetSyncer($this->root), new TranslationCatalogueAggregator($this->root));
    }
}
