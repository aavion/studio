<?php

declare(strict_types=1);

namespace App\Core\Package;

final readonly class PackageAssetRegistryWriter
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
        private PackageAssetFilesystem $filesystem,
        private PackageAssetRegistryBuilder $registryBuilder = new PackageAssetRegistryBuilder(),
    ) {
    }

    public function ensureRegistryFilesExist(): void
    {
        foreach (self::CSS_REGISTRIES as $bucket => $path) {
            $this->ensureRegistryFile($path, $this->registryBuilder->buildCssRegistry([], $bucket));
        }

        foreach (self::JAVASCRIPT_REGISTRIES as $bucket => $path) {
            $this->ensureRegistryFile($path, $this->registryBuilder->buildJavaScriptRegistry([], $bucket));
        }
    }

    /**
     * @param list<PackageAssetContribution> $contributions
     */
    public function write(array $contributions): void
    {
        $this->writeThen($contributions, static function (): void {
        });
    }

    /**
     * @param list<PackageAssetContribution> $contributions
     */
    public function writeThen(array $contributions, callable $afterWrite): void
    {
        $files = [];

        foreach (self::CSS_REGISTRIES as $bucket => $path) {
            $files[$path] = $this->registryBuilder->buildCssRegistry($contributions, $bucket);
        }

        foreach (self::JAVASCRIPT_REGISTRIES as $bucket => $path) {
            $files[$path] = $this->registryBuilder->buildJavaScriptRegistry($contributions, $bucket);
        }

        $this->assertWritableTargets(array_keys($files));
        $backups = $this->backupTargets(array_keys($files));

        try {
            foreach ($files as $path => $contents) {
                $this->filesystem->writeFile($path, $contents);
            }

            $afterWrite();
        } catch (\Throwable $error) {
            $this->restoreTargets($backups);

            throw $error;
        } finally {
            $this->removeBackups($backups);
        }
    }

    /**
     * @param list<string> $paths
     */
    private function assertWritableTargets(array $paths): void
    {
        foreach ($paths as $path) {
            $absolutePath = $this->filesystem->absolutePath($path);

            if (is_link($absolutePath)) {
                throw new \RuntimeException(sprintf('Target file "%s" must not be a symlink.', $path));
            }

            if (is_dir($absolutePath)) {
                throw new \RuntimeException(sprintf('Target file "%s" exists as a directory.', $path));
            }
        }
    }

    private function ensureRegistryFile(string $path, string $contents): void
    {
        $absolutePath = $this->filesystem->absolutePath($path);

        if (is_link($absolutePath)) {
            throw new \RuntimeException(sprintf('Target file "%s" must not be a symlink.', $path));
        }

        if (is_dir($absolutePath)) {
            throw new \RuntimeException(sprintf('Target file "%s" exists as a directory.', $path));
        }

        if (is_file($absolutePath)) {
            return;
        }

        $this->filesystem->writeFile($path, $contents);
    }

    /**
     * @param list<string> $paths
     *
     * @return array<string, string|null>
     */
    private function backupTargets(array $paths): array
    {
        $backups = [];

        foreach ($paths as $path) {
            $absolutePath = $this->filesystem->absolutePath($path);
            $backupPath = null;

            if (is_file($absolutePath)) {
                $backupPath = $absolutePath.'.backup-'.bin2hex(random_bytes(8));

                if (!copy($absolutePath, $backupPath)) {
                    throw new \RuntimeException(sprintf('Target file "%s" could not be backed up.', $path));
                }
            }

            $backups[$path] = $backupPath;
        }

        return $backups;
    }

    /**
     * @param array<string, string|null> $backups
     */
    private function restoreTargets(array $backups): void
    {
        foreach ($backups as $path => $backupPath) {
            $absolutePath = $this->filesystem->absolutePath($path);

            if (null === $backupPath) {
                if (is_file($absolutePath) || is_link($absolutePath)) {
                    @unlink($absolutePath);
                }

                continue;
            }

            @copy($backupPath, $absolutePath);
        }
    }

    /**
     * @param array<string, string|null> $backups
     */
    private function removeBackups(array $backups): void
    {
        foreach ($backups as $backupPath) {
            if (null !== $backupPath && is_file($backupPath)) {
                @unlink($backupPath);
            }
        }
    }
}
