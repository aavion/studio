<?php

declare(strict_types=1);

namespace App\Core\Extension;

final readonly class ExtensionAssetRegistryWriter
{
    private const CSS_REGISTRIES = [
        ExtensionAssetRegistryBuilder::BUCKET_EXTENSION => 'assets/styles/extensions/extension.css',
        ExtensionAssetRegistryBuilder::BUCKET_FRONTEND_THEME => 'assets/styles/extensions/frontend-theme.css',
        ExtensionAssetRegistryBuilder::BUCKET_BACKEND_THEME => 'assets/styles/extensions/backend-theme.css',
    ];

    private const JAVASCRIPT_REGISTRIES = [
        ExtensionAssetRegistryBuilder::BUCKET_EXTENSION => 'assets/js/extensions/extension.js',
        ExtensionAssetRegistryBuilder::BUCKET_FRONTEND_THEME => 'assets/js/extensions/frontend-theme.js',
        ExtensionAssetRegistryBuilder::BUCKET_BACKEND_THEME => 'assets/js/extensions/backend-theme.js',
    ];

    public function __construct(
        private ExtensionAssetFilesystem $filesystem,
        private ExtensionAssetRegistryBuilder $registryBuilder = new ExtensionAssetRegistryBuilder(),
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
     * @param list<ExtensionAssetContribution> $contributions
     */
    public function write(array $contributions): void
    {
        $this->writeThen($contributions, static function (): void {
        });
    }

    /**
     * @param list<ExtensionAssetContribution> $contributions
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
                    $this->removeFileOrLink($absolutePath);
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
                $this->removeFileOrLink($backupPath);
            }
        }
    }

    private function removeFileOrLink(string $path): void
    {
        if ('\\' === DIRECTORY_SEPARATOR && @rmdir($path)) {
            return;
        }

        @unlink($path);
    }
}
