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

        $this->removeGeneratedCatalogues();

        $targets = [];
        ksort($catalogues);
        foreach ($catalogues as $locale => $catalogue) {
            $relativeTarget = $this->runtimePath->relativeCataloguePath($locale);
            $target = $this->absolutePath($relativeTarget);
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }

            file_put_contents($target, Yaml::dump($catalogue, 6, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
            $targets[] = $relativeTarget;
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

    private function removeGeneratedCatalogues(): void
    {
        foreach ($this->runtimePath->generatedCataloguePaths() as $path) {
            if (is_file($path) && !is_link($path)) {
                unlink($path);
            }
        }
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
