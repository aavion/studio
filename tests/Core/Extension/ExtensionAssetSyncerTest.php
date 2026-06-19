<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Event\PublicEventDispatcher;
use App\Core\Event\PublicEventHookRegistry;
use App\Core\Extension\Event\ExtensionAssetRegistryBuildEvent;
use App\Core\Extension\Event\ExtensionAssetSyncCompletedEvent;
use App\Core\Extension\Event\ExtensionAssetSyncStartedEvent;
use App\Core\Extension\ExtensionAssetContribution;
use App\Core\Extension\ExtensionAssetFilesystem;
use App\Core\Extension\ExtensionAssetRegistryWriter;
use App\Core\Extension\ExtensionAssetSyncTarget;
use App\Core\Extension\ExtensionAssetSyncer;
use App\Core\Extension\ExtensionScope;
use App\Core\Workflow\WorkflowStatus;
use App\Tests\Support\FilesystemTestHelper;
use App\Tests\Support\NullWorkflowResultMessageReporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ExtensionAssetSyncerTest extends TestCase
{
    use FilesystemTestHelper;

    private string $root;

    protected function setUp(): void
    {
        $this->root = $this->createTemporaryDirectory('system-extension-assets');
        $this->writeTestFile($this->root, 'assets/extensions/.gitignore', "*\n!.gitignore\n!README.md\n");
        $this->writeTestFile($this->root, 'assets/extensions/README.md', "Extension asset mirror.\n");
        $this->writeTestFile($this->root, 'assets/extensions/stale/old.css', 'old');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testItMirrorsExtensionAssetsAndRebuildsRegistries(): void
    {
        $this->writeTestFile($this->root, 'extensions/demo/assets/module.css', '.icon { background-image: url("./images/icon.svg"); }');
        $this->writeTestFile($this->root, 'extensions/demo/assets/module.js', 'import helper from "./lib/helper.js";');
        $this->writeTestFile($this->root, 'extensions/demo/assets/images/icon.svg', '<svg></svg>');
        $this->writeTestFile($this->root, 'extensions/demo/assets/lib/helper.js', 'export default {};');
        $this->writeTestFile($this->root, 'extensions/demo/assets/vendor/library/index.js', 'import "./chunk.js";');
        $this->writeTestFile($this->root, 'extensions/demo/templates/widget.html.twig', '<div class="demo"></div>');

        $result = (new ExtensionAssetSyncer($this->root))->sync([
            new ExtensionAssetSyncTarget('demo', 'extensions/demo', [ExtensionScope::Module]),
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame(5, $result->context()['mirrored_assets']);
        self::assertFileDoesNotExist($this->root.'/assets/extensions/stale/old.css');
        self::assertFileExists($this->root.'/assets/extensions/.gitignore');
        self::assertFileExists($this->root.'/assets/extensions/README.md');
        self::assertSame('<svg></svg>', file_get_contents($this->root.'/assets/extensions/demo/images/icon.svg'));
        self::assertStringContainsString('url("../extensions/demo/images/icon.svg")', (string) file_get_contents($this->root.'/assets/extensions/demo/module.css'));
        self::assertSame('import "./chunk.js";', file_get_contents($this->root.'/assets/extensions/demo/vendor/library/index.js'));

        $cssRegistry = (string) file_get_contents($this->root.'/assets/styles/extensions/extension.css');
        $javaScriptRegistry = (string) file_get_contents($this->root.'/assets/js/extensions/extension.js');

        self::assertStringContainsString('@source "../../../extensions/demo/templates";', $cssRegistry);
        self::assertStringContainsString('@import "../../extensions/demo/module.css";', $cssRegistry);
        self::assertStringContainsString('import "../../extensions/demo/module.js";', $javaScriptRegistry);
        self::assertStringNotContainsString('vendor/library/index.js', $javaScriptRegistry);
    }

    public function testRegistryWriterCreatesMissingEmptyRegistries(): void
    {
        $root = $this->createTemporaryDirectory('system-extension-registry-writer');

        try {
            $writer = new ExtensionAssetRegistryWriter(new ExtensionAssetFilesystem($root));
            $writer->ensureRegistryFilesExist();

            self::assertStringContainsString('Generated CSS extension asset registry: extension.', (string) file_get_contents($root.'/assets/styles/extensions/extension.css'));
            self::assertStringContainsString('Generated CSS extension asset registry: frontend-theme.', (string) file_get_contents($root.'/assets/styles/extensions/frontend-theme.css'));
            self::assertStringContainsString('Generated CSS extension asset registry: backend-theme.', (string) file_get_contents($root.'/assets/styles/extensions/backend-theme.css'));
            self::assertStringContainsString('Generated JavaScript extension asset registry: extension.', (string) file_get_contents($root.'/assets/js/extensions/extension.js'));
            self::assertStringContainsString('Generated JavaScript extension asset registry: frontend-theme.', (string) file_get_contents($root.'/assets/js/extensions/frontend-theme.js'));
            self::assertStringContainsString('Generated JavaScript extension asset registry: backend-theme.', (string) file_get_contents($root.'/assets/js/extensions/backend-theme.js'));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testItRoutesFrontendAndBackendThemeAssetsToSeparateBuckets(): void
    {
        $this->writeTestFile($this->root, 'extensions/dual/assets/frontend/app.css', '.front {}');
        $this->writeTestFile($this->root, 'extensions/dual/assets/backend/app.css', '.back {}');
        $this->writeTestFile($this->root, 'extensions/dual/assets/shared/app.css', '.shared {}');
        $this->writeTestFile($this->root, 'extensions/dual/assets/theme.css', '.root {}');

        (new ExtensionAssetSyncer($this->root))->sync([
            new ExtensionAssetSyncTarget('dual', 'extensions/dual', [ExtensionScope::FrontendTheme, ExtensionScope::BackendTheme]),
        ]);

        self::assertStringContainsString('@import "../../extensions/dual/frontend/app.css";', (string) file_get_contents($this->root.'/assets/styles/extensions/frontend-theme.css'));
        self::assertStringContainsString('@import "../../extensions/dual/backend/app.css";', (string) file_get_contents($this->root.'/assets/styles/extensions/backend-theme.css'));
        self::assertStringNotContainsString('dual/theme.css', (string) file_get_contents($this->root.'/assets/styles/extensions/extension.css'));
        self::assertStringNotContainsString('dual/shared/app.css', (string) file_get_contents($this->root.'/assets/styles/extensions/extension.css'));
        self::assertStringNotContainsString('dual/frontend/app.css', (string) file_get_contents($this->root.'/assets/styles/extensions/extension.css'));
        self::assertStringNotContainsString('dual/theme.css', (string) file_get_contents($this->root.'/assets/styles/extensions/frontend-theme.css'));
        self::assertStringNotContainsString('dual/theme.css', (string) file_get_contents($this->root.'/assets/styles/extensions/backend-theme.css'));
    }

    public function testItAllowsSharedAssetsOnlyForGlobalExtensionScopes(): void
    {
        $this->writeTestFile($this->root, 'extensions/theme-module/assets/frontend/app.css', '.front {}');
        $this->writeTestFile($this->root, 'extensions/theme-module/assets/theme.css', '.global {}');

        (new ExtensionAssetSyncer($this->root))->sync([
            new ExtensionAssetSyncTarget('theme-module', 'extensions/theme-module', [ExtensionScope::FrontendTheme, ExtensionScope::Module]),
        ]);

        self::assertStringContainsString('@import "../../extensions/theme-module/frontend/app.css";', (string) file_get_contents($this->root.'/assets/styles/extensions/frontend-theme.css'));
        self::assertStringContainsString('@import "../../extensions/theme-module/theme.css";', (string) file_get_contents($this->root.'/assets/styles/extensions/extension.css'));
    }

    public function testItDoesNotRouteAreaAssetsForExtensionsWithoutMatchingThemeScope(): void
    {
        $this->writeTestFile($this->root, 'extensions/module/assets/frontend/app.css', '.front {}');
        $this->writeTestFile($this->root, 'extensions/module/assets/backend/app.css', '.back {}');
        $this->writeTestFile($this->root, 'extensions/module/assets/theme.css', '.global {}');

        (new ExtensionAssetSyncer($this->root))->sync([
            new ExtensionAssetSyncTarget('module', 'extensions/module', [ExtensionScope::Module]),
        ]);

        self::assertStringContainsString('@import "../../extensions/module/theme.css";', (string) file_get_contents($this->root.'/assets/styles/extensions/extension.css'));
        self::assertStringNotContainsString('module/frontend/app.css', (string) file_get_contents($this->root.'/assets/styles/extensions/extension.css'));
        self::assertStringNotContainsString('module/backend/app.css', (string) file_get_contents($this->root.'/assets/styles/extensions/extension.css'));
        self::assertStringNotContainsString('module/frontend/app.css', (string) file_get_contents($this->root.'/assets/styles/extensions/frontend-theme.css'));
        self::assertStringNotContainsString('module/backend/app.css', (string) file_get_contents($this->root.'/assets/styles/extensions/backend-theme.css'));
    }

    public function testItRegistersModuleJavaScriptEntrypoints(): void
    {
        $this->writeTestFile($this->root, 'extensions/module-assets/assets/app.mjs', 'import "./shared/util.mjs";');
        $this->writeTestFile($this->root, 'extensions/module-assets/assets/index.mjs', 'console.log("index");');
        $this->writeTestFile($this->root, 'extensions/module-assets/assets/module.mjs', 'console.log("module");');
        $this->writeTestFile($this->root, 'extensions/module-assets/assets/theme.mjs', 'console.log("theme");');
        $this->writeTestFile($this->root, 'extensions/module-assets/assets/feature.mjs', 'console.log("feature");');
        $this->writeTestFile($this->root, 'extensions/module-assets/assets/vendor/library/index.mjs', 'console.log("vendor");');

        $result = (new ExtensionAssetSyncer($this->root))->sync([
            new ExtensionAssetSyncTarget('module-assets', 'extensions/module-assets', [ExtensionScope::Module]),
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame(4, $result->context()['javascript_entries']);

        $javaScriptRegistry = (string) file_get_contents($this->root.'/assets/js/extensions/extension.js');

        self::assertStringContainsString('import "../../extensions/module-assets/app.mjs";', $javaScriptRegistry);
        self::assertStringContainsString('import "../../extensions/module-assets/index.mjs";', $javaScriptRegistry);
        self::assertStringContainsString('import "../../extensions/module-assets/module.mjs";', $javaScriptRegistry);
        self::assertStringContainsString('import "../../extensions/module-assets/theme.mjs";', $javaScriptRegistry);
        self::assertStringNotContainsString('feature.mjs', $javaScriptRegistry);
        self::assertStringNotContainsString('vendor/library/index.mjs', $javaScriptRegistry);
    }

    public function testItRegistersTemplateOnlyExtensionsAsTailwindSources(): void
    {
        $this->writeTestFile($this->root, 'extensions/templates-only/templates/widget.html.twig', '<div class="extension-widget"></div>');

        $result = (new ExtensionAssetSyncer($this->root))->sync([
            new ExtensionAssetSyncTarget('templates-only', 'extensions/templates-only', [ExtensionScope::Module]),
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame(0, $result->context()['mirrored_assets']);
        self::assertSame(1, $result->context()['tailwind_sources']);
        self::assertStringContainsString('@source "../../../extensions/templates-only/templates";', (string) file_get_contents($this->root.'/assets/styles/extensions/extension.css'));
    }

    public function testItDispatchesExtensionAssetHooksAndAcceptsRegistryContributions(): void
    {
        $this->writeTestFile($this->root, 'extensions/demo/assets/module.css', '.demo {}');
        $this->writeTestFile($this->root, 'extensions/demo/assets/generated.css', '.generated {}');

        $events = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ExtensionAssetSyncStartedEvent::class, static function (ExtensionAssetSyncStartedEvent $event) use (&$events): void {
            $events[] = 'started:'.$event->extensions()[0]->identifier();
        });
        $dispatcher->addListener(ExtensionAssetRegistryBuildEvent::class, static function (ExtensionAssetRegistryBuildEvent $event) use (&$events): void {
            $events[] = 'registry:'.count($event->contributions());
            $event->addContribution(ExtensionAssetContribution::css(
                'demo',
                ExtensionScope::Module,
                'assets/extensions/demo/generated.css',
            ));
        });
        $dispatcher->addListener(ExtensionAssetSyncCompletedEvent::class, static function (ExtensionAssetSyncCompletedEvent $event) use (&$events): void {
            $events[] = 'completed:'.$event->metrics()['css_entries'];
        });

        $result = (new ExtensionAssetSyncer(
            $this->root,
            eventDispatcher: new PublicEventDispatcher($dispatcher, new PublicEventHookRegistry(), new NullWorkflowResultMessageReporter()),
        ))->sync([
            new ExtensionAssetSyncTarget('demo', 'extensions/demo', [ExtensionScope::Module]),
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame(['started:demo', 'registry:1', 'completed:2'], $events);
        self::assertSame(2, $result->context()['css_entries']);
        self::assertStringContainsString('@import "../../extensions/demo/generated.css";', (string) file_get_contents($this->root.'/assets/styles/extensions/extension.css'));
    }

    public function testItReportsHookListenerFailuresWithoutThrowing(): void
    {
        $this->writeTestFile($this->root, 'extensions/demo/assets/module.css', '.demo {}');

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ExtensionAssetRegistryBuildEvent::class, static function (): void {
            throw new \RuntimeException('Subscriber failed');
        });

        $result = (new ExtensionAssetSyncer(
            $this->root,
            eventDispatcher: new PublicEventDispatcher($dispatcher, new PublicEventHookRegistry(), new NullWorkflowResultMessageReporter()),
        ))->sync([
            new ExtensionAssetSyncTarget('demo', 'extensions/demo', [ExtensionScope::Module]),
        ]);

        self::assertSame(WorkflowStatus::Failed, $result->status());
        self::assertSame('event.hook_listener_failed', $result->firstIssue()?->code());
        self::assertSame(ExtensionAssetRegistryBuildEvent::class, $result->firstIssue()?->context()['event']);
        self::assertFileExists($this->root.'/assets/extensions/stale/old.css');
        self::assertDirectoryDoesNotExist($this->root.'/assets/extensions/demo');
    }

    public function testItKeepsPreviousMirrorWhenRegistryWriteFails(): void
    {
        $this->writeTestFile($this->root, 'extensions/demo/assets/module.css', '.demo {}');
        $this->writeTestFile($this->root, 'assets/styles/extensions/extension.css', 'old extension registry');
        mkdir($this->root.'/assets/styles/extensions/frontend-theme.css', 0775, true);

        $result = (new ExtensionAssetSyncer($this->root))->sync([
            new ExtensionAssetSyncTarget('demo', 'extensions/demo', [ExtensionScope::Module]),
        ]);

        self::assertSame(WorkflowStatus::Failed, $result->status());
        self::assertSame('old extension registry', file_get_contents($this->root.'/assets/styles/extensions/extension.css'));
        self::assertFileExists($this->root.'/assets/extensions/stale/old.css');
        self::assertDirectoryDoesNotExist($this->root.'/assets/extensions/demo');
        self::assertSame([], glob($this->root.'/assets/.extensions.tmp-*'));
    }

    public function testRegistryWriterRollsBackWhenFollowUpFails(): void
    {
        $this->writeTestFile($this->root, 'assets/styles/extensions/extension.css', 'old extension registry');

        $writer = new ExtensionAssetRegistryWriter(new ExtensionAssetFilesystem($this->root));

        try {
            $writer->writeThen([
                ExtensionAssetContribution::css('demo', ExtensionScope::Module, 'assets/extensions/demo/module.css'),
            ], static function (): void {
                throw new \RuntimeException('follow-up failed');
            });
            self::fail('Expected registry write follow-up to fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('follow-up failed', $error->getMessage());
        }

        self::assertSame('old extension registry', file_get_contents($this->root.'/assets/styles/extensions/extension.css'));
        self::assertFileDoesNotExist($this->root.'/assets/styles/extensions/frontend-theme.css');
        self::assertFileDoesNotExist($this->root.'/assets/js/extensions/extension.js');
        self::assertSame([], glob($this->root.'/assets/styles/extensions/*.backup-*'));
        self::assertSame([], glob($this->root.'/assets/js/extensions/*.backup-*'));
    }

    public function testItRemovesDeactivatedExtensionMirrorAndRegistryEntries(): void
    {
        $this->writeTestFile($this->root, 'extensions/demo/assets/module.css', '.demo {}');
        $this->writeTestFile($this->root, 'extensions/demo/assets/module.js', 'console.log("demo");');
        $this->writeTestFile($this->root, 'extensions/demo/templates/widget.html.twig', '<div class="demo"></div>');

        $syncer = new ExtensionAssetSyncer($this->root);
        $syncer->sync([
            new ExtensionAssetSyncTarget('demo', 'extensions/demo', [ExtensionScope::Module]),
        ]);

        self::assertDirectoryExists($this->root.'/assets/extensions/demo');
        self::assertStringContainsString('extensions/demo/templates', (string) file_get_contents($this->root.'/assets/styles/extensions/extension.css'));
        self::assertStringContainsString('extensions/demo/module.css', (string) file_get_contents($this->root.'/assets/styles/extensions/extension.css'));
        self::assertStringContainsString('extensions/demo/module.js', (string) file_get_contents($this->root.'/assets/js/extensions/extension.js'));

        $syncer->sync([]);

        self::assertDirectoryDoesNotExist($this->root.'/assets/extensions/demo');
        self::assertStringNotContainsString('extensions/demo', (string) file_get_contents($this->root.'/assets/styles/extensions/extension.css'));
        self::assertStringNotContainsString('extensions/demo', (string) file_get_contents($this->root.'/assets/js/extensions/extension.js'));
    }

    public function testItFailsWhenMirrorDirectoryIsASymlink(): void
    {
        $this->removeDirectory($this->root.'/assets/extensions');
        mkdir($this->root.'/external-mirror', 0775, true);
        $this->createSymlinkOrSkip($this->root.'/external-mirror', $this->root.'/assets/extensions');

        $result = (new ExtensionAssetSyncer($this->root))->sync([]);
        unlink($this->root.'/assets/extensions');
        mkdir($this->root.'/assets/extensions', 0775, true);

        self::assertSame(WorkflowStatus::Failed, $result->status());
        self::assertSame('extension.asset_sync_failed', $result->firstIssue()?->code());
    }

    public function testItFailsWhenExtensionAssetRootIsBelowASymlink(): void
    {
        mkdir($this->root.'/external-extension/assets', 0775, true);
        $this->writeTestFile($this->root, 'external-extension/assets/module.css', '.demo {}');
        mkdir($this->root.'/extensions', 0775, true);
        $this->createSymlinkOrSkip($this->root.'/external-extension', $this->root.'/extensions/demo');

        $result = (new ExtensionAssetSyncer($this->root))->sync([
            new ExtensionAssetSyncTarget('demo', 'extensions/demo', [ExtensionScope::Module]),
        ]);
        unlink($this->root.'/extensions/demo');

        self::assertSame(WorkflowStatus::Failed, $result->status());
        self::assertSame('extension.asset_sync_failed', $result->firstIssue()?->code());
    }

    public function testItFailsWhenExtensionTemplateRootIsASymlink(): void
    {
        mkdir($this->root.'/extensions/demo', 0775, true);
        mkdir($this->root.'/external-templates', 0775, true);
        $this->createSymlinkOrSkip($this->root.'/external-templates', $this->root.'/extensions/demo/templates');

        $result = (new ExtensionAssetSyncer($this->root))->sync([
            new ExtensionAssetSyncTarget('demo', 'extensions/demo', [ExtensionScope::Module]),
        ]);
        unlink($this->root.'/extensions/demo/templates');

        self::assertSame(WorkflowStatus::Failed, $result->status());
        self::assertSame('extension.asset_sync_failed', $result->firstIssue()?->code());
    }
}
