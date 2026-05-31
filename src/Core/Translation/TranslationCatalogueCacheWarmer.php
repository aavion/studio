<?php

declare(strict_types=1);

namespace App\Core\Translation;

use App\Core\Package\ActivePackageAssetProviderInterface;
use App\Core\Package\PackageAssetSyncPackage;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Throwable;

final readonly class TranslationCatalogueCacheWarmer implements CacheWarmerInterface
{
    private TranslationRuntimePath $runtimePath;

    public function __construct(
        private string $projectDir,
        private TranslationCatalogueAggregator $aggregator,
        private ActivePackageAssetProviderInterface $packageProvider,
        ?TranslationRuntimePath $runtimePath = null,
    ) {
        $this->runtimePath = $runtimePath ?? TranslationRuntimePath::fromGlobals($projectDir);
    }

    /**
     * @return list<string>
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $packageProviderError = null;

        try {
            $packages = $this->packageProvider->packages();
        } catch (Throwable $error) {
            $packages = [];
            $packageProviderError = [
                'exception' => $error::class,
                'message' => $error->getMessage(),
            ];
        }

        try {
            $sourceHash = $this->aggregator->sourceHash($packages);

            if ($this->isFresh($sourceHash)) {
                return [];
            }

            $result = $this->aggregator->aggregate($packages);

            if ($result->isSuccess()) {
                $this->writeManifest($sourceHash, $result->context(), $packages, $packageProviderError);
            }
        } catch (Throwable) {
            return [];
        }

        return [];
    }

    public function isOptional(): bool
    {
        return true;
    }

    private function isFresh(string $sourceHash): bool
    {
        $manifest = $this->readManifest();

        return $sourceHash === ($manifest['source_hash'] ?? null)
            && is_string($manifest['generated_hash'] ?? null)
            && ($manifest['generated_hash'] ?? null) === $this->generatedHash($this->manifestTargets($manifest));
    }

    /**
     * @param array<string, mixed> $context
     * @param list<PackageAssetSyncPackage> $packages
     * @param array<string, string>|null $packageProviderError
     */
    private function writeManifest(string $sourceHash, array $context, array $packages, ?array $packageProviderError): void
    {
        $targets = $this->targets($context);
        $payload = [
            'source_hash' => $sourceHash,
            'generated_hash' => $this->generatedHash($targets),
            'generated_at' => date(DATE_ATOM),
            'targets' => $targets,
            'packages' => array_map(static fn (PackageAssetSyncPackage $package): string => $package->identifier(), $packages),
            'locales' => (int) ($context['locales'] ?? 0),
            'files' => (int) ($context['files'] ?? 0),
        ];

        if (null !== $packageProviderError) {
            $payload['package_provider_error'] = $packageProviderError;
        }

        $path = $this->manifestPath();
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
            return;
        }

        @file_put_contents($path, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(): array
    {
        $path = $this->manifestPath();
        if (!is_file($path)) {
            return [];
        }

        $payload = json_decode((string) file_get_contents($path), true);

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return list<string>
     */
    private function manifestTargets(array $manifest): array
    {
        $targets = $manifest['targets'] ?? [];

        return is_array($targets) ? array_values(array_filter($targets, 'is_string')) : [];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return list<string>
     */
    private function targets(array $context): array
    {
        $targets = $context['targets'] ?? [];

        return is_array($targets) ? array_values(array_filter($targets, 'is_string')) : [];
    }

    /**
     * @param list<string> $targets
     */
    private function generatedHash(array $targets): ?string
    {
        if ([] === $targets) {
            return null;
        }

        sort($targets);
        $fingerprints = [];

        foreach ($targets as $target) {
            $path = $this->projectDir.'/'.$target;

            if (!is_file($path)) {
                return null;
            }

            $fingerprints[] = $target."\0".(string) hash_file('sha256', $path);
        }

        return hash('sha256', implode("\n", $fingerprints));
    }

    private function manifestPath(): string
    {
        return $this->runtimePath->absoluteManifestPath();
    }
}
