<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Filesystem\PathGuard;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Workflow\OperationIssue;
use App\Core\Workflow\OperationResult;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

final readonly class PackageAssetSyncer
{
    private const CSS_REGISTRIES = [
        PackageAssetRegistryBuilder::BUCKET_EXTENSION => 'assets/styles/packages/extension.css',
        PackageAssetRegistryBuilder::BUCKET_FRONTEND_THEME => 'assets/styles/packages/frontend-theme.css',
        PackageAssetRegistryBuilder::BUCKET_BACKEND_THEME => 'assets/styles/packages/backend-theme.css',
    ];

    private const JAVASCRIPT_REGISTRIES = [
        PackageAssetRegistryBuilder::BUCKET_EXTENSION => 'assets/js/packages/extension.js',
        PackageAssetRegistryBuilder::BUCKET_FRONTEND_THEME => 'assets/js/packages/frontend-theme.js',
        PackageAssetRegistryBuilder::BUCKET_BACKEND_THEME => 'assets/js/packages/backend-theme.js',
    ];

    public function __construct(
        private string $projectDir,
        private PackageAssetPathRewriter $pathRewriter = new PackageAssetPathRewriter(),
        private PackageAssetRegistryBuilder $registryBuilder = new PackageAssetRegistryBuilder(),
        private PathGuard $pathGuard = new PathGuard(),
    ) {
    }

    /**
     * @param iterable<PackageAssetSyncPackage> $packages
     *
     * @return OperationResult<array{packages: int, mirrored_assets: int, css_entries: int, javascript_entries: int, tailwind_sources: int}>
     */
    public function sync(iterable $packages): OperationResult
    {
        try {
            return $this->doSync($packages);
        } catch (Throwable $error) {
            $context = [
                'exception' => $error::class,
                'message' => $error->getMessage(),
            ];

            return OperationResult::failed([
                OperationIssue::create(MessageCode::PACKAGE_ASSET_SYNC_FAILED, MessageKey::PACKAGE_ASSET_SYNC_FAILED, [
                    '%message%' => $error->getMessage(),
                ], $context, MessageLevel::Error),
            ], $context);
        }
    }

    /**
     * @param iterable<PackageAssetSyncPackage> $packages
     *
     * @return OperationResult<array{packages: int, mirrored_assets: int, css_entries: int, javascript_entries: int, tailwind_sources: int}>
     */
    private function doSync(iterable $packages): OperationResult
    {
        $packages = $this->sortedPackages($packages);
        $this->resetMirrorDirectory();

        $contributions = [];
        $mirroredAssets = 0;

        foreach ($packages as $package) {
            $packageAssetRoot = $package->directory().'/assets';

            if ($this->pathExists($packageAssetRoot)) {
                if (!$this->isSafeDirectory($packageAssetRoot)) {
                    throw new RuntimeException(sprintf('Package asset root "%s" is not a safe directory.', $packageAssetRoot));
                }

                foreach ($this->assetFiles($packageAssetRoot) as $assetFile) {
                    ++$mirroredAssets;
                    $targetPath = $this->mirrorAsset($package, $packageAssetRoot, $assetFile);
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
            if ($this->pathExists($templatePath) && !$this->isSafeDirectory($templatePath)) {
                throw new RuntimeException(sprintf('Package template root "%s" is not a safe directory.', $templatePath));
            }

            if (is_dir($this->absolutePath($templatePath))) {
                $contributions[] = PackageAssetContribution::tailwindSource($package->identifier(), $this->primaryScope($package), $templatePath);
            }
        }

        $this->writeRegistries($contributions);

        $context = [
            'packages' => count($packages),
            'mirrored_assets' => $mirroredAssets,
            'css_entries' => $this->countByType($contributions, PackageAssetContribution::TYPE_CSS),
            'javascript_entries' => $this->countByType($contributions, PackageAssetContribution::TYPE_JAVASCRIPT),
            'tailwind_sources' => $this->countByType($contributions, PackageAssetContribution::TYPE_TAILWIND_SOURCE),
        ];

        return OperationResult::success($context, $context, [
            Message::info(MessageCode::PACKAGE_ASSET_SYNC_COMPLETED, MessageKey::PACKAGE_ASSET_SYNC_COMPLETED, [
                '%assets%' => (string) $mirroredAssets,
                '%packages%' => (string) count($packages),
            ], $context),
        ]);
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

    private function resetMirrorDirectory(): void
    {
        $rootPath = 'assets/packages';
        $root = $this->absolutePath($rootPath);

        if (is_link($root)) {
            throw new RuntimeException(sprintf('Package asset mirror "%s" must not be a symlink.', $rootPath));
        }

        if (file_exists($root) && !is_dir($root)) {
            throw new RuntimeException(sprintf('Package asset mirror "%s" must be a directory.', $rootPath));
        }

        $this->ensureDirectory($rootPath);
        $entries = scandir($root);

        if (false === $entries) {
            throw new RuntimeException(sprintf('Package asset mirror "%s" cannot be read.', $rootPath));
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry || '.gitignore' === $entry || 'README.md' === $entry) {
                continue;
            }

            $this->removePath($root.DIRECTORY_SEPARATOR.$entry);

            if (file_exists($root.DIRECTORY_SEPARATOR.$entry) || is_link($root.DIRECTORY_SEPARATOR.$entry)) {
                throw new RuntimeException(sprintf('Package asset mirror entry "%s" could not be removed.', 'assets/packages/'.$entry));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function assetFiles(string $packageAssetRoot): array
    {
        $absoluteRoot = $this->absolutePath($packageAssetRoot);
        $files = [];

        if (!$this->isSafeDirectory($packageAssetRoot)) {
            throw new RuntimeException(sprintf('Package asset root "%s" is not a safe directory.', $packageAssetRoot));
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absoluteRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($absoluteRoot) + 1));
            $files[] = 'assets/'.$relative;
        }

        sort($files);

        return $files;
    }

    private function mirrorAsset(PackageAssetSyncPackage $package, string $packageAssetRoot, string $assetFile): string
    {
        $targetPath = 'assets/packages/'.$package->identifier().'/'.substr($assetFile, strlen('assets/'));
        $sourcePath = $package->directory().'/'.$assetFile;
        $absoluteTarget = $this->absolutePath($targetPath);
        $this->ensureParentDirectory($targetPath);

        if (str_ends_with($assetFile, '.css')) {
            $contents = $this->readFile($sourcePath);
            $this->writeFile($targetPath, $this->pathRewriter->rewriteCss(
                $contents,
                $sourcePath,
                $packageAssetRoot,
                'assets/packages/'.$package->identifier(),
            ));

            return $targetPath;
        }

        if (str_ends_with($assetFile, '.js') || str_ends_with($assetFile, '.mjs')) {
            $contents = $this->readFile($sourcePath);
            $this->writeFile($targetPath, $this->pathRewriter->rewriteJavaScript(
                $contents,
                $sourcePath,
                $packageAssetRoot,
                $targetPath,
                'assets/packages/'.$package->identifier(),
            ));

            return $targetPath;
        }

        if (!copy($this->absolutePath($sourcePath), $absoluteTarget)) {
            throw new RuntimeException(sprintf('Package asset "%s" could not be copied to "%s".', $sourcePath, $targetPath));
        }

        return $targetPath;
    }

    /**
     * @param list<PackageAssetContribution> $contributions
     */
    private function writeRegistries(array $contributions): void
    {
        foreach (self::CSS_REGISTRIES as $bucket => $path) {
            $this->writeFile($path, $this->registryBuilder->buildCssRegistry($contributions, $bucket));
        }

        foreach (self::JAVASCRIPT_REGISTRIES as $bucket => $path) {
            $this->writeFile($path, $this->registryBuilder->buildJavaScriptRegistry($contributions, $bucket));
        }
    }

    private function writeFile(string $path, string $contents): void
    {
        $absolutePath = $this->absolutePath($path);

        if (is_link($absolutePath)) {
            throw new RuntimeException(sprintf('Target file "%s" must not be a symlink.', $path));
        }

        if (is_dir($absolutePath)) {
            throw new RuntimeException(sprintf('Target file "%s" exists as a directory.', $path));
        }

        $this->ensureParentDirectory($path);

        if (false === file_put_contents($absolutePath, $contents, LOCK_EX)) {
            throw new RuntimeException(sprintf('Target file "%s" could not be written.', $path));
        }
    }

    private function scopeForAsset(PackageAssetSyncPackage $package, string $assetFile): ?PackageScope
    {
        if (str_starts_with($assetFile, 'assets/frontend/') && $package->hasScope(PackageScope::FrontendTheme)) {
            return PackageScope::FrontendTheme;
        }

        if (str_starts_with($assetFile, 'assets/backend/') && $package->hasScope(PackageScope::BackendTheme)) {
            return PackageScope::BackendTheme;
        }

        return $this->primaryScope($package);
    }

    private function primaryScope(PackageAssetSyncPackage $package): PackageScope
    {
        foreach ([PackageScope::Module, PackageScope::CaptchaProvider, PackageScope::EditorProvider, PackageScope::FrontendTheme, PackageScope::BackendTheme] as $scope) {
            if ($package->hasScope($scope)) {
                return $scope;
            }
        }

        return $package->scopes()[0];
    }

    private function isRegistryEntrypoint(string $assetFile): bool
    {
        return in_array(basename($assetFile), ['app.css', 'app.js', 'index.css', 'index.js', 'module.css', 'module.js', 'theme.css', 'theme.js'], true);
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

    private function absolutePath(string $path): string
    {
        return rtrim($this->projectDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $this->pathGuard->relativePath($path));
    }

    private function isSafeDirectory(string $path): bool
    {
        $absolutePath = $this->absolutePath($path);

        return is_dir($absolutePath)
            && !is_link($absolutePath)
            && null === $this->pathGuard->symlinkAncestor($this->projectDir, $path);
    }

    private function pathExists(string $path): bool
    {
        $absolutePath = $this->absolutePath($path);

        return file_exists($absolutePath) || is_link($absolutePath);
    }

    private function ensureDirectory(string $path): void
    {
        $absolutePath = $this->absolutePath($path);

        if (is_link($absolutePath)) {
            throw new RuntimeException(sprintf('Directory "%s" must not be a symlink.', $path));
        }

        if (null !== $this->pathGuard->symlinkAncestor($this->projectDir, $path)) {
            throw new RuntimeException(sprintf('Directory "%s" must not be below a symlink.', $path));
        }

        if (file_exists($absolutePath) && !is_dir($absolutePath)) {
            throw new RuntimeException(sprintf('Directory "%s" exists as a file.', $path));
        }

        if (!is_dir($absolutePath) && !mkdir($absolutePath, 0775, true) && !is_dir($absolutePath)) {
            throw new RuntimeException(sprintf('Directory "%s" could not be created.', $path));
        }
    }

    private function ensureParentDirectory(string $path): void
    {
        $parent = dirname($this->pathGuard->relativePath($path));

        if ('.' === $parent) {
            return;
        }

        $this->ensureDirectory($parent);
    }

    private function readFile(string $path): string
    {
        $contents = file_get_contents($this->absolutePath($path));

        if (false === $contents) {
            throw new RuntimeException(sprintf('Package asset "%s" could not be read.', $path));
        }

        return $contents;
    }

    private function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!@unlink($path)) {
                throw new RuntimeException(sprintf('Path "%s" could not be removed.', $path));
            }

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);

        if (false === $entries) {
            throw new RuntimeException(sprintf('Directory "%s" could not be read.', $path));
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $this->removePath($path.DIRECTORY_SEPARATOR.$entry);
        }

        if (!@rmdir($path)) {
            throw new RuntimeException(sprintf('Directory "%s" could not be removed.', $path));
        }
    }
}
