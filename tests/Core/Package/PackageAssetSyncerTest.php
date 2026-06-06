<?php

declare(strict_types=1);

namespace App\Tests\Core\Package;

use App\Core\Event\PublicEventDispatcher;
use App\Core\Event\PublicEventHookRegistry;
use App\Core\Package\Event\PackageAssetRegistryBuildEvent;
use App\Core\Package\Event\PackageAssetSyncCompletedEvent;
use App\Core\Package\Event\PackageAssetSyncStartedEvent;
use App\Core\Package\PackageAssetContribution;
use App\Core\Package\PackageAssetFilesystem;
use App\Core\Package\PackageAssetRegistryWriter;
use App\Core\Package\PackageAssetSyncPackage;
use App\Core\Package\PackageAssetSyncer;
use App\Core\Package\PackageScope;
use App\Core\Workflow\WorkflowStatus;
use App\Tests\Support\FilesystemTestHelper;
use App\Tests\Support\NullWorkflowResultMessageReporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class PackageAssetSyncerTest extends TestCase
{
    use FilesystemTestHelper;

    private string $root;

    protected function setUp(): void
    {
        $this->root = $this->createTemporaryDirectory('system-package-assets');
        $this->writeTestFile($this->root, 'assets/packages/.gitignore', "*\n!.gitignore\n!README.md\n");
        $this->writeTestFile($this->root, 'assets/packages/README.md', "Package asset mirror.\n");
        $this->writeTestFile($this->root, 'assets/packages/stale/old.css', 'old');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testItMirrorsPackageAssetsAndRebuildsRegistries(): void
    {
        $this->writeTestFile($this->root, 'packages/demo/assets/module.css', '.icon { background-image: url("./images/icon.svg"); }');
        $this->writeTestFile($this->root, 'packages/demo/assets/module.js', 'import helper from "./lib/helper.js";');
        $this->writeTestFile($this->root, 'packages/demo/assets/images/icon.svg', '<svg></svg>');
        $this->writeTestFile($this->root, 'packages/demo/assets/lib/helper.js', 'export default {};');
        $this->writeTestFile($this->root, 'packages/demo/assets/vendor/library/index.js', 'import "./chunk.js";');
        $this->writeTestFile($this->root, 'packages/demo/templates/widget.html.twig', '<div class="demo"></div>');

        $result = (new PackageAssetSyncer($this->root))->sync([
            new PackageAssetSyncPackage('demo', 'packages/demo', [PackageScope::Module]),
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame(5, $result->context()['mirrored_assets']);
        self::assertFileDoesNotExist($this->root.'/assets/packages/stale/old.css');
        self::assertFileExists($this->root.'/assets/packages/.gitignore');
        self::assertFileExists($this->root.'/assets/packages/README.md');
        self::assertSame('<svg></svg>', file_get_contents($this->root.'/assets/packages/demo/images/icon.svg'));
        self::assertStringContainsString('url("../packages/demo/images/icon.svg")', (string) file_get_contents($this->root.'/assets/packages/demo/module.css'));
        self::assertSame('import "./chunk.js";', file_get_contents($this->root.'/assets/packages/demo/vendor/library/index.js'));

        $cssRegistry = (string) file_get_contents($this->root.'/assets/styles/packages/extension.css');
        $javaScriptRegistry = (string) file_get_contents($this->root.'/assets/js/packages/extension.js');

        self::assertStringContainsString('@source "../../../packages/demo/templates";', $cssRegistry);
        self::assertStringContainsString('@import "../../packages/demo/module.css";', $cssRegistry);
        self::assertStringContainsString('import "../../packages/demo/module.js";', $javaScriptRegistry);
        self::assertStringNotContainsString('vendor/library/index.js', $javaScriptRegistry);
    }

    public function testRegistryWriterCreatesMissingEmptyRegistries(): void
    {
        $root = $this->createTemporaryDirectory('system-package-registry-writer');

        try {
            $writer = new PackageAssetRegistryWriter(new PackageAssetFilesystem($root));
            $writer->ensureRegistryFilesExist();

            self::assertStringContainsString('Generated CSS package asset registry: extension.', (string) file_get_contents($root.'/assets/styles/packages/extension.css'));
            self::assertStringContainsString('Generated CSS package asset registry: frontend-theme.', (string) file_get_contents($root.'/assets/styles/packages/frontend-theme.css'));
            self::assertStringContainsString('Generated CSS package asset registry: backend-theme.', (string) file_get_contents($root.'/assets/styles/packages/backend-theme.css'));
            self::assertStringContainsString('Generated JavaScript package asset registry: extension.', (string) file_get_contents($root.'/assets/js/packages/extension.js'));
            self::assertStringContainsString('Generated JavaScript package asset registry: frontend-theme.', (string) file_get_contents($root.'/assets/js/packages/frontend-theme.js'));
            self::assertStringContainsString('Generated JavaScript package asset registry: backend-theme.', (string) file_get_contents($root.'/assets/js/packages/backend-theme.js'));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testItRoutesFrontendAndBackendThemeAssetsToSeparateBuckets(): void
    {
        $this->writeTestFile($this->root, 'packages/dual/assets/frontend/app.css', '.front {}');
        $this->writeTestFile($this->root, 'packages/dual/assets/backend/app.css', '.back {}');
        $this->writeTestFile($this->root, 'packages/dual/assets/shared/app.css', '.shared {}');
        $this->writeTestFile($this->root, 'packages/dual/assets/theme.css', '.root {}');

        (new PackageAssetSyncer($this->root))->sync([
            new PackageAssetSyncPackage('dual', 'packages/dual', [PackageScope::FrontendTheme, PackageScope::BackendTheme]),
        ]);

        self::assertStringContainsString('@import "../../packages/dual/frontend/app.css";', (string) file_get_contents($this->root.'/assets/styles/packages/frontend-theme.css'));
        self::assertStringContainsString('@import "../../packages/dual/backend/app.css";', (string) file_get_contents($this->root.'/assets/styles/packages/backend-theme.css'));
        self::assertStringNotContainsString('dual/theme.css', (string) file_get_contents($this->root.'/assets/styles/packages/extension.css'));
        self::assertStringNotContainsString('dual/shared/app.css', (string) file_get_contents($this->root.'/assets/styles/packages/extension.css'));
        self::assertStringNotContainsString('dual/frontend/app.css', (string) file_get_contents($this->root.'/assets/styles/packages/extension.css'));
        self::assertStringNotContainsString('dual/theme.css', (string) file_get_contents($this->root.'/assets/styles/packages/frontend-theme.css'));
        self::assertStringNotContainsString('dual/theme.css', (string) file_get_contents($this->root.'/assets/styles/packages/backend-theme.css'));
    }

    public function testItAllowsSharedAssetsOnlyForGlobalPackageScopes(): void
    {
        $this->writeTestFile($this->root, 'packages/theme-module/assets/frontend/app.css', '.front {}');
        $this->writeTestFile($this->root, 'packages/theme-module/assets/theme.css', '.global {}');

        (new PackageAssetSyncer($this->root))->sync([
            new PackageAssetSyncPackage('theme-module', 'packages/theme-module', [PackageScope::FrontendTheme, PackageScope::Module]),
        ]);

        self::assertStringContainsString('@import "../../packages/theme-module/frontend/app.css";', (string) file_get_contents($this->root.'/assets/styles/packages/frontend-theme.css'));
        self::assertStringContainsString('@import "../../packages/theme-module/theme.css";', (string) file_get_contents($this->root.'/assets/styles/packages/extension.css'));
    }

    public function testItDoesNotRouteAreaAssetsForPackagesWithoutMatchingThemeScope(): void
    {
        $this->writeTestFile($this->root, 'packages/module/assets/frontend/app.css', '.front {}');
        $this->writeTestFile($this->root, 'packages/module/assets/backend/app.css', '.back {}');
        $this->writeTestFile($this->root, 'packages/module/assets/theme.css', '.global {}');

        (new PackageAssetSyncer($this->root))->sync([
            new PackageAssetSyncPackage('module', 'packages/module', [PackageScope::Module]),
        ]);

        self::assertStringContainsString('@import "../../packages/module/theme.css";', (string) file_get_contents($this->root.'/assets/styles/packages/extension.css'));
        self::assertStringNotContainsString('module/frontend/app.css', (string) file_get_contents($this->root.'/assets/styles/packages/extension.css'));
        self::assertStringNotContainsString('module/backend/app.css', (string) file_get_contents($this->root.'/assets/styles/packages/extension.css'));
        self::assertStringNotContainsString('module/frontend/app.css', (string) file_get_contents($this->root.'/assets/styles/packages/frontend-theme.css'));
        self::assertStringNotContainsString('module/backend/app.css', (string) file_get_contents($this->root.'/assets/styles/packages/backend-theme.css'));
    }

    public function testItRegistersModuleJavaScriptEntrypoints(): void
    {
        $this->writeTestFile($this->root, 'packages/module-assets/assets/app.mjs', 'import "./shared/util.mjs";');
        $this->writeTestFile($this->root, 'packages/module-assets/assets/index.mjs', 'console.log("index");');
        $this->writeTestFile($this->root, 'packages/module-assets/assets/module.mjs', 'console.log("module");');
        $this->writeTestFile($this->root, 'packages/module-assets/assets/theme.mjs', 'console.log("theme");');
        $this->writeTestFile($this->root, 'packages/module-assets/assets/feature.mjs', 'console.log("feature");');
        $this->writeTestFile($this->root, 'packages/module-assets/assets/vendor/library/index.mjs', 'console.log("vendor");');

        $result = (new PackageAssetSyncer($this->root))->sync([
            new PackageAssetSyncPackage('module-assets', 'packages/module-assets', [PackageScope::Module]),
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame(4, $result->context()['javascript_entries']);

        $javaScriptRegistry = (string) file_get_contents($this->root.'/assets/js/packages/extension.js');

        self::assertStringContainsString('import "../../packages/module-assets/app.mjs";', $javaScriptRegistry);
        self::assertStringContainsString('import "../../packages/module-assets/index.mjs";', $javaScriptRegistry);
        self::assertStringContainsString('import "../../packages/module-assets/module.mjs";', $javaScriptRegistry);
        self::assertStringContainsString('import "../../packages/module-assets/theme.mjs";', $javaScriptRegistry);
        self::assertStringNotContainsString('feature.mjs', $javaScriptRegistry);
        self::assertStringNotContainsString('vendor/library/index.mjs', $javaScriptRegistry);
    }

    public function testItRegistersTemplateOnlyPackagesAsTailwindSources(): void
    {
        $this->writeTestFile($this->root, 'packages/templates-only/templates/widget.html.twig', '<div class="package-widget"></div>');

        $result = (new PackageAssetSyncer($this->root))->sync([
            new PackageAssetSyncPackage('templates-only', 'packages/templates-only', [PackageScope::Module]),
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame(0, $result->context()['mirrored_assets']);
        self::assertSame(1, $result->context()['tailwind_sources']);
        self::assertStringContainsString('@source "../../../packages/templates-only/templates";', (string) file_get_contents($this->root.'/assets/styles/packages/extension.css'));
    }

    public function testItDispatchesPackageAssetHooksAndAcceptsRegistryContributions(): void
    {
        $this->writeTestFile($this->root, 'packages/demo/assets/module.css', '.demo {}');
        $this->writeTestFile($this->root, 'packages/demo/assets/generated.css', '.generated {}');

        $events = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(PackageAssetSyncStartedEvent::class, static function (PackageAssetSyncStartedEvent $event) use (&$events): void {
            $events[] = 'started:'.$event->packages()[0]->identifier();
        });
        $dispatcher->addListener(PackageAssetRegistryBuildEvent::class, static function (PackageAssetRegistryBuildEvent $event) use (&$events): void {
            $events[] = 'registry:'.count($event->contributions());
            $event->addContribution(PackageAssetContribution::css(
                'demo',
                PackageScope::Module,
                'assets/packages/demo/generated.css',
            ));
        });
        $dispatcher->addListener(PackageAssetSyncCompletedEvent::class, static function (PackageAssetSyncCompletedEvent $event) use (&$events): void {
            $events[] = 'completed:'.$event->metrics()['css_entries'];
        });

        $result = (new PackageAssetSyncer(
            $this->root,
            eventDispatcher: new PublicEventDispatcher($dispatcher, new PublicEventHookRegistry(), new NullWorkflowResultMessageReporter()),
        ))->sync([
            new PackageAssetSyncPackage('demo', 'packages/demo', [PackageScope::Module]),
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame(['started:demo', 'registry:1', 'completed:2'], $events);
        self::assertSame(2, $result->context()['css_entries']);
        self::assertStringContainsString('@import "../../packages/demo/generated.css";', (string) file_get_contents($this->root.'/assets/styles/packages/extension.css'));
    }

    public function testItReportsHookListenerFailuresWithoutThrowing(): void
    {
        $this->writeTestFile($this->root, 'packages/demo/assets/module.css', '.demo {}');

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(PackageAssetRegistryBuildEvent::class, static function (): void {
            throw new \RuntimeException('Subscriber failed');
        });

        $result = (new PackageAssetSyncer(
            $this->root,
            eventDispatcher: new PublicEventDispatcher($dispatcher, new PublicEventHookRegistry(), new NullWorkflowResultMessageReporter()),
        ))->sync([
            new PackageAssetSyncPackage('demo', 'packages/demo', [PackageScope::Module]),
        ]);

        self::assertSame(WorkflowStatus::Failed, $result->status());
        self::assertSame('event.hook_listener_failed', $result->firstIssue()?->code());
        self::assertSame(PackageAssetRegistryBuildEvent::class, $result->firstIssue()?->context()['event']);
        self::assertFileExists($this->root.'/assets/packages/stale/old.css');
        self::assertDirectoryDoesNotExist($this->root.'/assets/packages/demo');
    }

    public function testItKeepsPreviousMirrorWhenRegistryWriteFails(): void
    {
        $this->writeTestFile($this->root, 'packages/demo/assets/module.css', '.demo {}');
        $this->writeTestFile($this->root, 'assets/styles/packages/extension.css', 'old extension registry');
        mkdir($this->root.'/assets/styles/packages/frontend-theme.css', 0775, true);

        $result = (new PackageAssetSyncer($this->root))->sync([
            new PackageAssetSyncPackage('demo', 'packages/demo', [PackageScope::Module]),
        ]);

        self::assertSame(WorkflowStatus::Failed, $result->status());
        self::assertSame('old extension registry', file_get_contents($this->root.'/assets/styles/packages/extension.css'));
        self::assertFileExists($this->root.'/assets/packages/stale/old.css');
        self::assertDirectoryDoesNotExist($this->root.'/assets/packages/demo');
        self::assertSame([], glob($this->root.'/assets/.packages.tmp-*'));
    }

    public function testRegistryWriterRollsBackWhenFollowUpFails(): void
    {
        $this->writeTestFile($this->root, 'assets/styles/packages/extension.css', 'old extension registry');

        $writer = new PackageAssetRegistryWriter(new PackageAssetFilesystem($this->root));

        try {
            $writer->writeThen([
                PackageAssetContribution::css('demo', PackageScope::Module, 'assets/packages/demo/module.css'),
            ], static function (): void {
                throw new \RuntimeException('follow-up failed');
            });
            self::fail('Expected registry write follow-up to fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('follow-up failed', $error->getMessage());
        }

        self::assertSame('old extension registry', file_get_contents($this->root.'/assets/styles/packages/extension.css'));
        self::assertFileDoesNotExist($this->root.'/assets/styles/packages/frontend-theme.css');
        self::assertFileDoesNotExist($this->root.'/assets/js/packages/extension.js');
        self::assertSame([], glob($this->root.'/assets/styles/packages/*.backup-*'));
        self::assertSame([], glob($this->root.'/assets/js/packages/*.backup-*'));
    }

    public function testItRemovesDeactivatedPackageMirrorAndRegistryEntries(): void
    {
        $this->writeTestFile($this->root, 'packages/demo/assets/module.css', '.demo {}');
        $this->writeTestFile($this->root, 'packages/demo/assets/module.js', 'console.log("demo");');
        $this->writeTestFile($this->root, 'packages/demo/templates/widget.html.twig', '<div class="demo"></div>');

        $syncer = new PackageAssetSyncer($this->root);
        $syncer->sync([
            new PackageAssetSyncPackage('demo', 'packages/demo', [PackageScope::Module]),
        ]);

        self::assertDirectoryExists($this->root.'/assets/packages/demo');
        self::assertStringContainsString('packages/demo/templates', (string) file_get_contents($this->root.'/assets/styles/packages/extension.css'));
        self::assertStringContainsString('packages/demo/module.css', (string) file_get_contents($this->root.'/assets/styles/packages/extension.css'));
        self::assertStringContainsString('packages/demo/module.js', (string) file_get_contents($this->root.'/assets/js/packages/extension.js'));

        $syncer->sync([]);

        self::assertDirectoryDoesNotExist($this->root.'/assets/packages/demo');
        self::assertStringNotContainsString('packages/demo', (string) file_get_contents($this->root.'/assets/styles/packages/extension.css'));
        self::assertStringNotContainsString('packages/demo', (string) file_get_contents($this->root.'/assets/js/packages/extension.js'));
    }

    public function testItFailsWhenMirrorDirectoryIsASymlink(): void
    {
        $this->removeDirectory($this->root.'/assets/packages');
        mkdir($this->root.'/external-mirror', 0775, true);
        $this->createSymlinkOrSkip($this->root.'/external-mirror', $this->root.'/assets/packages');

        $result = (new PackageAssetSyncer($this->root))->sync([]);
        unlink($this->root.'/assets/packages');
        mkdir($this->root.'/assets/packages', 0775, true);

        self::assertSame(WorkflowStatus::Failed, $result->status());
        self::assertSame('package.asset_sync_failed', $result->firstIssue()?->code());
    }

    public function testItFailsWhenPackageAssetRootIsBelowASymlink(): void
    {
        mkdir($this->root.'/external-package/assets', 0775, true);
        $this->writeTestFile($this->root, 'external-package/assets/module.css', '.demo {}');
        mkdir($this->root.'/packages', 0775, true);
        $this->createSymlinkOrSkip($this->root.'/external-package', $this->root.'/packages/demo');

        $result = (new PackageAssetSyncer($this->root))->sync([
            new PackageAssetSyncPackage('demo', 'packages/demo', [PackageScope::Module]),
        ]);
        unlink($this->root.'/packages/demo');

        self::assertSame(WorkflowStatus::Failed, $result->status());
        self::assertSame('package.asset_sync_failed', $result->firstIssue()?->code());
    }

    public function testItFailsWhenPackageTemplateRootIsASymlink(): void
    {
        mkdir($this->root.'/packages/demo', 0775, true);
        mkdir($this->root.'/external-templates', 0775, true);
        $this->createSymlinkOrSkip($this->root.'/external-templates', $this->root.'/packages/demo/templates');

        $result = (new PackageAssetSyncer($this->root))->sync([
            new PackageAssetSyncPackage('demo', 'packages/demo', [PackageScope::Module]),
        ]);
        unlink($this->root.'/packages/demo/templates');

        self::assertSame(WorkflowStatus::Failed, $result->status());
        self::assertSame('package.asset_sync_failed', $result->firstIssue()?->code());
    }
}
