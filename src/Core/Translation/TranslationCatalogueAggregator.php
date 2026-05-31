<?php

declare(strict_types=1);

namespace App\Core\Translation;

use App\Core\Filesystem\PathGuard;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Package\PackageAssetSyncPackage;
use App\Core\Workflow\WorkflowResult;
use Symfony\Component\Yaml\Yaml;
use Throwable;

final readonly class TranslationCatalogueAggregator
{
    private const CORE_SOURCE_DIRECTORY = 'translations/languages';
    private TranslationRuntimePath $runtimePath;

    public function __construct(
        private string $projectDir,
        private PathGuard $pathGuard = new PathGuard(),
        ?TranslationRuntimePath $runtimePath = null,
    ) {
        $this->runtimePath = $runtimePath ?? TranslationRuntimePath::fromGlobals($projectDir);
    }

    /**
     * @param iterable<PackageAssetSyncPackage> $packages
     *
     * @return WorkflowResult<array{packages: int, locales: int, files: int, targets: list<string>}>
     */
    public function aggregate(iterable $packages): WorkflowResult
    {
        try {
            return $this->doAggregate($packages);
        } catch (Throwable $error) {
            $context = [
                'exception' => $error::class,
                'message' => $error->getMessage(),
                'target_pattern' => $this->runtimePath->relativeCataloguePattern(),
            ];

            return WorkflowResult::failed([
                Message::exception(MessageCode::TRANSLATION_AGGREGATE_FAILED, MessageKey::TRANSLATION_AGGREGATE_FAILED, [
                    '%path%' => $this->runtimePath->relativeDirectory().'/messages.*.yaml',
                ], $context),
            ], $context);
        }
    }

    /**
     * @param iterable<PackageAssetSyncPackage> $packages
     */
    public function sourceHash(iterable $packages): string
    {
        $packages = $this->sortedPackages($packages);
        $sources = array_merge($this->coreSources(), $this->packageSources($packages));
        $fingerprints = [];

        foreach ($sources as $source) {
            $fingerprints[] = implode("\0", [
                $source['locale'],
                $this->relativeSourcePath($source['path']),
                (string) hash_file('sha256', $source['path']),
            ]);
        }

        return hash('sha256', implode("\n", $fingerprints));
    }

    /**
     * @param iterable<PackageAssetSyncPackage> $packages
     *
     * @return WorkflowResult<array{packages: int, locales: int, files: int, targets: list<string>}>
     */
    private function doAggregate(iterable $packages): WorkflowResult
    {
        $packages = $this->sortedPackages($packages);
        $sources = array_merge($this->coreSources(), $this->packageSources($packages));
        $catalogues = [];
        $files = 0;

        foreach ($sources as $source) {
            $locale = $source['locale'];
            $catalogues[$locale] ??= [];
            $catalogues[$locale] = $this->merge($catalogues[$locale], $this->readYaml($source['path']), $source['path']);
            ++$files;
        }

        $stagingDirectory = $this->runtimePath->relativeDirectory().'.tmp-'.bin2hex(random_bytes(8));
        $staging = $this->absolutePath($stagingDirectory);

        $targets = [];
        try {
            $this->ensureDirectory($staging);
            $this->preserveRuntimeMetadata($staging);
            ksort($catalogues);
            foreach ($catalogues as $locale => $catalogue) {
                $relativeTarget = $this->runtimePath->relativeCataloguePath($locale);
                $stagedTarget = $stagingDirectory.'/messages.'.$locale.'.yaml';
                $target = $this->absolutePath($stagedTarget);
                $this->ensureDirectory(dirname($target));

                $this->writeFile($target, Yaml::dump($catalogue, 6, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
                $targets[] = $relativeTarget;
            }

            $this->replaceRuntimeDirectory($stagingDirectory);
        } catch (Throwable $error) {
            $this->removeDirectory($this->absolutePath($stagingDirectory));

            throw $error;
        }

        $context = [
            'packages' => count($packages),
            'locales' => count($catalogues),
            'files' => $files,
            'targets' => $targets,
        ];

        return WorkflowResult::success($context, $context, [
            Message::create(MessageCode::TRANSLATION_AGGREGATE_COMPLETED, MessageKey::TRANSLATION_AGGREGATE_COMPLETED, [
                '%files%' => (string) $files,
                '%locales%' => (string) count($catalogues),
                '%packages%' => (string) count($packages),
            ], $context, MessageLevel::Success),
        ]);
    }

    /**
     * @return list<array{locale: string, path: string}>
     */
    private function coreSources(): array
    {
        return $this->sourcesFromLanguageRoot(self::CORE_SOURCE_DIRECTORY);
    }

    /**
     * @param list<PackageAssetSyncPackage> $packages
     *
     * @return list<array{locale: string, path: string}>
     */
    private function packageSources(array $packages): array
    {
        $sources = [];

        foreach ($packages as $package) {
            $languageRoot = $package->directory().'/languages';
            if (!$this->isSafePackageLanguageRoot($languageRoot)) {
                continue;
            }

            $sources = array_merge($sources, $this->sourcesFromLanguageRoot($languageRoot));
        }

        return $sources;
    }

    /**
     * @return list<array{locale: string, path: string}>
     */
    private function sourcesFromLanguageRoot(string $relativeRoot): array
    {
        if (!$this->pathGuard->isRelativePath($relativeRoot)) {
            return [];
        }

        $root = $this->absolutePath($relativeRoot);
        if (!is_dir($root)) {
            return [];
        }

        $sources = [];
        foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $locale = basename($directory);
            if (1 !== preg_match('/^[a-z][a-z0-9]*(?:[_-][A-Za-z0-9]+)*$/', $locale)) {
                continue;
            }

            foreach (glob($directory.'/*.yaml') ?: [] as $path) {
                $sources[] = [
                    'locale' => $locale,
                    'path' => $path,
                ];
            }
        }

        usort($sources, static fn (array $left, array $right): int => [$left['locale'], $left['path']] <=> [$right['locale'], $right['path']]);

        return $sources;
    }

    private function isSafePackageLanguageRoot(string $path): bool
    {
        if (!$this->pathGuard->isRelativePath($path)) {
            return false;
        }

        $relativePath = $this->pathGuard->relativePath($path);
        if (!str_starts_with($relativePath, 'packages/') || !str_ends_with($relativePath, '/languages')) {
            return false;
        }

        return is_dir($this->absolutePath($relativePath));
    }

    /**
     * @return array<string, mixed>
     */
    private function readYaml(string $path): array
    {
        $data = Yaml::parseFile($path);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     *
     * @return array<string, mixed>
     */
    private function merge(array $left, array $right, string $source, string $prefix = ''): array
    {
        foreach ($right as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix.'.'.$key;

            if (!array_key_exists($key, $left)) {
                $left[$key] = $value;
                continue;
            }

            if (is_array($left[$key]) && is_array($value)) {
                $left[$key] = $this->merge($left[$key], $value, $source, $path);
                continue;
            }

            throw new TranslationCatalogueCollisionException($path, $source);
        }

        return $left;
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

    private function replaceRuntimeDirectory(string $stagingDirectory): void
    {
        $runtimeDirectory = $this->absolutePath($this->runtimePath->relativeDirectory());
        $staging = $this->absolutePath($stagingDirectory);
        $backupDirectory = $this->runtimePath->relativeDirectory().'.backup-'.bin2hex(random_bytes(8));
        $backup = $this->absolutePath($backupDirectory);

        if (is_dir($runtimeDirectory) || is_link($runtimeDirectory)) {
            if (!@rename($runtimeDirectory, $backup)) {
                throw new \RuntimeException(sprintf('Runtime translation directory "%s" could not be moved aside.', $this->runtimePath->relativeDirectory()));
            }
        }

        if (!@rename($staging, $runtimeDirectory)) {
            if (is_dir($backup) && !file_exists($runtimeDirectory)) {
                @rename($backup, $runtimeDirectory);
            }

            throw new \RuntimeException(sprintf('Runtime translation directory "%s" could not be replaced.', $this->runtimePath->relativeDirectory()));
        }

        $this->removeDirectory($backup);
    }

    private function preserveRuntimeMetadata(string $staging): void
    {
        $runtimeDirectory = $this->absolutePath($this->runtimePath->relativeDirectory());

        if (!is_dir($runtimeDirectory) || is_link($runtimeDirectory)) {
            return;
        }

        foreach (scandir($runtimeDirectory) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry || 1 === preg_match('/^messages\.[^.]+\.yaml$/', $entry)) {
                continue;
            }

            $source = $runtimeDirectory.DIRECTORY_SEPARATOR.$entry;
            $target = $staging.DIRECTORY_SEPARATOR.$entry;

            if (is_link($source)) {
                continue;
            }

            if (is_dir($source)) {
                $this->copyDirectory($source, $target);
                continue;
            }

            if (is_file($source) && !copy($source, $target)) {
                throw new \RuntimeException(sprintf('Runtime translation metadata "%s" could not be staged.', $entry));
            }
        }
    }

    private function copyDirectory(string $source, string $target): void
    {
        $this->ensureDirectory($target);

        foreach (scandir($source) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $sourcePath = $source.DIRECTORY_SEPARATOR.$entry;
            $targetPath = $target.DIRECTORY_SEPARATOR.$entry;

            if (is_link($sourcePath)) {
                continue;
            }

            if (is_dir($sourcePath)) {
                $this->copyDirectory($sourcePath, $targetPath);
                continue;
            }

            if (is_file($sourcePath) && !copy($sourcePath, $targetPath)) {
                throw new \RuntimeException(sprintf('Runtime translation metadata "%s" could not be staged.', $sourcePath));
            }
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (is_link($path)) {
            throw new \RuntimeException(sprintf('Runtime translation directory "%s" must not be a symlink.', $path));
        }

        if (file_exists($path) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Runtime translation directory "%s" exists as a file.', $path));
        }

        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Runtime translation directory "%s" could not be created.', $path));
        }
    }

    private function writeFile(string $path, string $contents): void
    {
        $temporaryPath = $path.'.tmp-'.bin2hex(random_bytes(8));

        if (false === file_put_contents($temporaryPath, $contents, LOCK_EX)) {
            @unlink($temporaryPath);
            throw new \RuntimeException(sprintf('Runtime translation file "%s" could not be written.', $path));
        }

        if (!@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new \RuntimeException(sprintf('Runtime translation file "%s" could not be replaced.', $path));
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }

        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $this->removeDirectory($path.DIRECTORY_SEPARATOR.$entry);
        }

        @rmdir($path);
    }

    private function absolutePath(string $path): string
    {
        return $this->projectDir.'/'.$this->pathGuard->relativePath($path);
    }

    private function relativeSourcePath(string $path): string
    {
        $prefix = rtrim($this->projectDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }
}
