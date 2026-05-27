<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Event\PublicEventDispatcher;
use App\Core\Filesystem\PathGuard;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Package\Event\PackageAssetRegistryBuildEvent;
use App\Core\Package\Event\PackageAssetSyncCompletedEvent;
use App\Core\Package\Event\PackageAssetSyncStartedEvent;
use App\Core\Workflow\WorkflowResult;
use RuntimeException;
use Throwable;

final readonly class PackageAssetSyncer
{
    private PackageAssetFilesystem $filesystem;

    private PackageAssetMirror $mirror;

    private PackageAssetRegistryWriter $registryWriter;

    public function __construct(
        string $projectDir,
        PackageAssetPathRewriter $pathRewriter = new PackageAssetPathRewriter(),
        PackageAssetRegistryBuilder $registryBuilder = new PackageAssetRegistryBuilder(),
        PathGuard $pathGuard = new PathGuard(),
        private ?PublicEventDispatcher $eventDispatcher = null,
    ) {
        $this->filesystem = new PackageAssetFilesystem($projectDir, $pathGuard);
        $this->mirror = new PackageAssetMirror($this->filesystem, $pathRewriter);
        $this->registryWriter = new PackageAssetRegistryWriter($this->filesystem, $registryBuilder);
    }

    /**
     * @param iterable<PackageAssetSyncPackage> $packages
     *
     * @return WorkflowResult<array{packages: int, mirrored_assets: int, css_entries: int, javascript_entries: int, tailwind_sources: int}>
     */
    public function sync(iterable $packages): WorkflowResult
    {
        try {
            return $this->doSync($packages);
        } catch (Throwable $error) {
            $context = [
                'exception' => $error::class,
                'message' => $error->getMessage(),
            ];

            return WorkflowResult::failed([
                Message::exception(MessageCode::PACKAGE_ASSET_SYNC_FAILED, MessageKey::PACKAGE_ASSET_SYNC_FAILED, [
                    '%message%' => $error->getMessage(),
                ], $context),
            ], $context);
        }
    }

    /**
     * @param iterable<PackageAssetSyncPackage> $packages
     *
     * @return WorkflowResult<array{packages: int, mirrored_assets: int, css_entries: int, javascript_entries: int, tailwind_sources: int}>
     */
    private function doSync(iterable $packages): WorkflowResult
    {
        $packages = $this->sortedPackages($packages);
        $started = $this->dispatchHook(new PackageAssetSyncStartedEvent($packages));
        if (null !== $started) {
            return $started;
        }

        $this->mirror->resetMirrorDirectory();

        $contributions = [];
        $mirroredAssets = 0;

        foreach ($packages as $package) {
            $packageAssetRoot = $package->directory().'/assets';

            if ($this->filesystem->pathExists($packageAssetRoot)) {
                if (!$this->filesystem->isSafeDirectory($packageAssetRoot)) {
                    throw new RuntimeException(sprintf('Package asset root "%s" is not a safe directory.', $packageAssetRoot));
                }

                foreach ($this->mirror->assetFiles($packageAssetRoot) as $assetFile) {
                    ++$mirroredAssets;
                    $targetPath = $this->mirror->mirrorAsset($package, $packageAssetRoot, $assetFile);
                    $scope = $this->scopeForAsset($package, $assetFile);

                    if (null === $scope || $this->isVendorAsset($assetFile) || !$this->isRegistryEntrypoint($assetFile)) {
                        if (!$this->isStyleOrScript($assetFile)) {
                            $contributions[] = PackageAssetContribution::staticAsset($package->identifier(), $this->primaryScope($package), $targetPath);
                        }

                        continue;
                    }

                    if (str_ends_with($assetFile, '.css')) {
                        $contributions[] = PackageAssetContribution::css($package->identifier(), $scope, $targetPath);
                    }

                    if (str_ends_with($assetFile, '.js') || str_ends_with($assetFile, '.mjs')) {
                        $contributions[] = PackageAssetContribution::javaScript($package->identifier(), $scope, $targetPath);
                    }
                }
            }

            $templatePath = $package->directory().'/templates';
            if ($this->filesystem->pathExists($templatePath) && !$this->filesystem->isSafeDirectory($templatePath)) {
                throw new RuntimeException(sprintf('Package template root "%s" is not a safe directory.', $templatePath));
            }

            if (is_dir($this->filesystem->absolutePath($templatePath))) {
                $contributions[] = PackageAssetContribution::tailwindSource($package->identifier(), $this->primaryScope($package), $templatePath);
            }
        }

        if (null !== $this->eventDispatcher) {
            $registryEvent = new PackageAssetRegistryBuildEvent($packages, $contributions);
            $registryResult = $this->eventDispatcher->dispatch($registryEvent, [
                'operation' => 'package_asset_sync',
                'phase' => 'registry_build',
            ]);
            if (!$registryResult->isSuccess()) {
                return WorkflowResult::failed($registryResult->issues(), [
                    'packages' => count($packages),
                    'hook' => $registryEvent::class,
                ]);
            }

            $contributions = $registryEvent->contributions();
        }

        $this->registryWriter->write($contributions);

        $context = [
            'packages' => count($packages),
            'mirrored_assets' => $mirroredAssets,
            'css_entries' => $this->countByType($contributions, PackageAssetContribution::TYPE_CSS),
            'javascript_entries' => $this->countByType($contributions, PackageAssetContribution::TYPE_JAVASCRIPT),
            'tailwind_sources' => $this->countByType($contributions, PackageAssetContribution::TYPE_TAILWIND_SOURCE),
        ];

        $completed = $this->dispatchHook(new PackageAssetSyncCompletedEvent($packages, $context));
        if (null !== $completed) {
            return $completed;
        }

        return WorkflowResult::success($context, $context, [
            Message::create(MessageCode::PACKAGE_ASSET_SYNC_COMPLETED, MessageKey::PACKAGE_ASSET_SYNC_COMPLETED, [
                '%assets%' => (string) $mirroredAssets,
                '%packages%' => (string) count($packages),
            ], $context, MessageLevel::Success),
        ]);
    }

    /**
     * @return WorkflowResult<array{packages: int, mirrored_assets: int, css_entries: int, javascript_entries: int, tailwind_sources: int}>|null
     */
    private function dispatchHook(PackageAssetSyncStartedEvent|PackageAssetSyncCompletedEvent $event): ?WorkflowResult
    {
        if (null === $this->eventDispatcher) {
            return null;
        }

        $phase = $event instanceof PackageAssetSyncCompletedEvent ? 'completed' : 'started';
        $result = $this->eventDispatcher->dispatch($event, [
            'operation' => 'package_asset_sync',
            'phase' => $phase,
        ]);
        if ($result->isSuccess()) {
            return null;
        }

        $context = [
            'packages' => count($event->packages()),
            'hook' => $event::class,
        ];

        if ($event instanceof PackageAssetSyncCompletedEvent) {
            $context += $event->metrics();
        }

        return WorkflowResult::failed($result->issues(), $context);
    }

    /**
     * @param iterable<PackageAssetSyncPackage> $packages
     *
     * @return list<PackageAssetSyncPackage>
     */
    private function sortedPackages(iterable $packages): array
    {
        $sorted = [];

        foreach ($packages as $package) {
            $sorted[] = $package;
        }

        usort($sorted, static fn (PackageAssetSyncPackage $left, PackageAssetSyncPackage $right): int => $left->identifier() <=> $right->identifier());

        return $sorted;
    }

    private function scopeForAsset(PackageAssetSyncPackage $package, string $assetFile): ?PackageScope
    {
        if (str_starts_with($assetFile, 'assets/frontend/')) {
            return $package->hasScope(PackageScope::FrontendTheme) ? PackageScope::FrontendTheme : null;
        }

        if (str_starts_with($assetFile, 'assets/backend/')) {
            return $package->hasScope(PackageScope::BackendTheme) ? PackageScope::BackendTheme : null;
        }

        return $this->globalScope($package);
    }

    private function globalScope(PackageAssetSyncPackage $package): ?PackageScope
    {
        foreach ([PackageScope::Module, PackageScope::SystemTemplate, PackageScope::CaptchaProvider, PackageScope::EditorProvider] as $scope) {
            if ($package->hasScope($scope)) {
                return $scope;
            }
        }

        return null;
    }

    private function primaryScope(PackageAssetSyncPackage $package): PackageScope
    {
        foreach ([PackageScope::Module, PackageScope::SystemTemplate, PackageScope::CaptchaProvider, PackageScope::EditorProvider, PackageScope::FrontendTheme, PackageScope::BackendTheme] as $scope) {
            if ($package->hasScope($scope)) {
                return $scope;
            }
        }

        return $package->scopes()[0];
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
     * @param list<PackageAssetContribution> $contributions
     */
    private function countByType(array $contributions, string $type): int
    {
        return count(array_filter($contributions, static fn (PackageAssetContribution $contribution): bool => $type === $contribution->type()));
    }

}
