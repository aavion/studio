<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Event\PublicEventDispatcher;
use App\Core\Filesystem\PathGuard;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Extension\Event\ExtensionAssetRegistryBuildEvent;
use App\Core\Extension\Event\ExtensionAssetSyncCompletedEvent;
use App\Core\Extension\Event\ExtensionAssetSyncStartedEvent;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Workflow\WorkflowResult;
use RuntimeException;
use Throwable;

final readonly class ExtensionAssetSyncer
{
    private ExtensionAssetFilesystem $filesystem;

    private ExtensionAssetMirror $mirror;

    private ExtensionAssetRegistryWriter $registryWriter;

    public function __construct(
        string $projectDir,
        ExtensionAssetPathRewriter $pathRewriter = new ExtensionAssetPathRewriter(),
        ExtensionAssetRegistryBuilder $registryBuilder = new ExtensionAssetRegistryBuilder(),
        PathGuard $pathGuard = new PathGuard(),
        private ?PublicEventDispatcher $eventDispatcher = null,
    ) {
        $this->filesystem = new ExtensionAssetFilesystem($projectDir, $pathGuard);
        $this->mirror = new ExtensionAssetMirror($this->filesystem, $pathRewriter);
        $this->registryWriter = new ExtensionAssetRegistryWriter($this->filesystem, $registryBuilder);
    }

    /**
     * @param iterable<ExtensionAssetSyncTarget> $extensions
     *
     * @return WorkflowResult<array{extensions: int, mirrored_assets: int, css_entries: int, javascript_entries: int, tailwind_sources: int}>
     */
    public function sync(iterable $extensions): WorkflowResult
    {
        try {
            return $this->doSync($extensions);
        } catch (Throwable $error) {
            $context = [
                'exception' => $error::class,
                'message' => $error->getMessage(),
            ];

            return WorkflowResult::failed([
                Message::exception(ExtensionMessageCode::EXTENSION_ASSET_SYNC_FAILED, ExtensionMessageKey::EXTENSION_ASSET_SYNC_FAILED, [
                    '%message%' => $error->getMessage(),
                ], $context),
            ], $context);
        }
    }

    /**
     * @param iterable<ExtensionAssetSyncTarget> $extensions
     *
     * @return WorkflowResult<array{extensions: int, mirrored_assets: int, css_entries: int, javascript_entries: int, tailwind_sources: int}>
     */
    private function doSync(iterable $extensions): WorkflowResult
    {
        $extensions = $this->sortedExtensions($extensions);
        $this->registryWriter->ensureRegistryFilesExist();

        $started = $this->dispatchHook(new ExtensionAssetSyncStartedEvent($extensions));
        if (null !== $started) {
            return $started;
        }

        $mirrorRoot = $this->mirror->prepareMirrorDirectory();

        $contributions = [];
        $mirroredAssets = 0;

        try {
            foreach ($extensions as $extension) {
                $extensionAssetRoot = $extension->directory().'/assets';

                if ($this->filesystem->pathExists($extensionAssetRoot)) {
                    if (!$this->filesystem->isSafeDirectory($extensionAssetRoot)) {
                        throw new RuntimeException(sprintf('Extension asset root "%s" is not a safe directory.', $extensionAssetRoot));
                    }

                    foreach ($this->mirror->assetFiles($extensionAssetRoot) as $assetFile) {
                        ++$mirroredAssets;
                        $targetPath = $this->mirror->mirrorAsset($extension, $extensionAssetRoot, $assetFile, $mirrorRoot);
                        $scope = $this->scopeForAsset($extension, $assetFile);

                        if (null === $scope || $this->isVendorAsset($assetFile) || !$this->isRegistryEntrypoint($assetFile)) {
                            if (!$this->isStyleOrScript($assetFile)) {
                                $contributions[] = ExtensionAssetContribution::staticAsset($extension->identifier(), $this->primaryScope($extension), $targetPath);
                            }

                            continue;
                        }

                        if (str_ends_with($assetFile, '.css')) {
                            $contributions[] = ExtensionAssetContribution::css($extension->identifier(), $scope, $targetPath);
                        }

                        if (str_ends_with($assetFile, '.js') || str_ends_with($assetFile, '.mjs')) {
                            $contributions[] = ExtensionAssetContribution::javaScript($extension->identifier(), $scope, $targetPath);
                        }
                    }
                }

                $templatePath = $extension->directory().'/templates';
                if ($this->filesystem->pathExists($templatePath) && !$this->filesystem->isSafeDirectory($templatePath)) {
                    throw new RuntimeException(sprintf('Extension template root "%s" is not a safe directory.', $templatePath));
                }

                if (is_dir($this->filesystem->absolutePath($templatePath))) {
                    $contributions[] = ExtensionAssetContribution::tailwindSource($extension->identifier(), $this->primaryScope($extension), $templatePath);
                }
            }

            if (null !== $this->eventDispatcher) {
                $registryEvent = new ExtensionAssetRegistryBuildEvent($extensions, $contributions);
                $registryResult = $this->eventDispatcher->dispatch($registryEvent, [
                    'operation' => 'extension_asset_sync',
                    'phase' => 'registry_build',
                ]);
                if (!$registryResult->isSuccess()) {
                    $this->mirror->discardMirrorDirectory($mirrorRoot);

                    return WorkflowResult::failed($registryResult->issues(), [
                        'extensions' => count($extensions),
                        'hook' => $registryEvent::class,
                    ]);
                }

                $contributions = $registryEvent->contributions();
            }

            $this->registryWriter->writeThen(
                $contributions,
                function () use ($mirrorRoot): void {
                    $this->mirror->commitMirrorDirectory($mirrorRoot);
                },
            );
        } catch (Throwable $error) {
            $this->mirror->discardMirrorDirectory($mirrorRoot);

            throw $error;
        }

        $context = [
            'extensions' => count($extensions),
            'mirrored_assets' => $mirroredAssets,
            'css_entries' => $this->countByType($contributions, ExtensionAssetContribution::TYPE_CSS),
            'javascript_entries' => $this->countByType($contributions, ExtensionAssetContribution::TYPE_JAVASCRIPT),
            'tailwind_sources' => $this->countByType($contributions, ExtensionAssetContribution::TYPE_TAILWIND_SOURCE),
        ];

        $completed = $this->dispatchHook(new ExtensionAssetSyncCompletedEvent($extensions, $context));
        if (null !== $completed) {
            return $completed;
        }

        return WorkflowResult::success($context, $context, [
            Message::create(ExtensionMessageCode::EXTENSION_ASSET_SYNC_COMPLETED, ExtensionMessageKey::EXTENSION_ASSET_SYNC_COMPLETED, [
                '%assets%' => (string) $mirroredAssets,
                '%extensions%' => (string) count($extensions),
            ], $context, MessageLevel::Success),
        ]);
    }

    /**
     * @return WorkflowResult<array{extensions: int, mirrored_assets: int, css_entries: int, javascript_entries: int, tailwind_sources: int}>|null
     */
    private function dispatchHook(ExtensionAssetSyncStartedEvent|ExtensionAssetSyncCompletedEvent $event): ?WorkflowResult
    {
        if (null === $this->eventDispatcher) {
            return null;
        }

        $phase = $event instanceof ExtensionAssetSyncCompletedEvent ? 'completed' : 'started';
        $result = $this->eventDispatcher->dispatch($event, [
            'operation' => 'extension_asset_sync',
            'phase' => $phase,
        ]);
        if ($result->isSuccess()) {
            return null;
        }

        $context = [
            'extensions' => count($event->extensions()),
            'hook' => $event::class,
        ];

        if ($event instanceof ExtensionAssetSyncCompletedEvent) {
            $context += $event->metrics();
        }

        return WorkflowResult::failed($result->issues(), $context);
    }

    /**
     * @param iterable<ExtensionAssetSyncTarget> $extensions
     *
     * @return list<ExtensionAssetSyncTarget>
     */
    private function sortedExtensions(iterable $extensions): array
    {
        $sorted = [];

        foreach ($extensions as $extension) {
            $sorted[] = $extension;
        }

        usort($sorted, static fn (ExtensionAssetSyncTarget $left, ExtensionAssetSyncTarget $right): int => $left->identifier() <=> $right->identifier());

        return $sorted;
    }

    private function scopeForAsset(ExtensionAssetSyncTarget $extension, string $assetFile): ?ExtensionScope
    {
        if (str_starts_with($assetFile, 'assets/frontend/')) {
            return $extension->hasScope(ExtensionScope::FrontendTheme) ? ExtensionScope::FrontendTheme : null;
        }

        if (str_starts_with($assetFile, 'assets/backend/')) {
            return $extension->hasScope(ExtensionScope::BackendTheme) ? ExtensionScope::BackendTheme : null;
        }

        return $this->globalScope($extension);
    }

    private function globalScope(ExtensionAssetSyncTarget $extension): ?ExtensionScope
    {
        foreach ([ExtensionScope::Module, ExtensionScope::SystemTemplate, ExtensionScope::CaptchaProvider, ExtensionScope::EditorProvider, ExtensionScope::Api] as $scope) {
            if ($extension->hasScope($scope)) {
                return $scope;
            }
        }

        return null;
    }

    private function primaryScope(ExtensionAssetSyncTarget $extension): ExtensionScope
    {
        foreach ([ExtensionScope::Module, ExtensionScope::SystemTemplate, ExtensionScope::CaptchaProvider, ExtensionScope::EditorProvider, ExtensionScope::Api, ExtensionScope::FrontendTheme, ExtensionScope::BackendTheme] as $scope) {
            if ($extension->hasScope($scope)) {
                return $scope;
            }
        }

        return $extension->scopes()[0];
    }

    private function isRegistryEntrypoint(string $assetFile): bool
    {
        return in_array(basename($assetFile), ['app.css', 'app.js', 'app.mjs', 'index.css', 'index.js', 'index.mjs', 'module.css', 'module.js', 'module.mjs', 'theme.css', 'theme.js', 'theme.mjs'], true);
    }

    private function isStyleOrScript(string $assetFile): bool
    {
        return str_ends_with($assetFile, '.css') || str_ends_with($assetFile, '.js') || str_ends_with($assetFile, '.mjs');
    }

    private function isVendorAsset(string $assetFile): bool
    {
        $path = '/'.str_replace('\\', '/', $assetFile).'/';

        return str_contains($path, '/vendor/')
            || str_contains($path, '/vendors/')
            || str_contains($path, '/node_modules/');
    }

    /**
     * @param list<ExtensionAssetContribution> $contributions
     */
    private function countByType(array $contributions, string $type): int
    {
        return count(array_filter($contributions, static fn (ExtensionAssetContribution $contribution): bool => $type === $contribution->type()));
    }

}
