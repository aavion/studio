<?php

declare(strict_types=1);

namespace App\Core\Package;

use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

final readonly class PackageDiscoveryCacheWarmer implements CacheWarmerInterface
{
    public function __construct(private PackageDiscoveryDispatcher $dispatcher)
    {
    }

    /**
     * @return list<string>
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $lockFile = $this->lockFile($cacheDir);

        if (is_file($lockFile)) {
            return [];
        }

        if (!$this->writeLock($lockFile)) {
            return [];
        }

        $result = $this->dispatcher->dispatch('cache_warmup');
        $this->writeSummary($cacheDir, $result->toArray());

        if (!$result->isSuccess()) {
            @unlink($lockFile);
        }

        return [];
    }

    public function isOptional(): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeSummary(string $cacheDir, array $payload): void
    {
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0777, true) && !is_dir($cacheDir)) {
            return;
        }

        @file_put_contents(
            rtrim($cacheDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'studio-package-discovery-warmup.json',
            (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    private function writeLock(string $lockFile): bool
    {
        $directory = dirname($lockFile);

        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            return false;
        }

        return false !== @file_put_contents($lockFile, date(DATE_ATOM), LOCK_EX);
    }

    private function lockFile(string $cacheDir): string
    {
        return rtrim($cacheDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'studio-package-discovery-warmup.lock';
    }
}
