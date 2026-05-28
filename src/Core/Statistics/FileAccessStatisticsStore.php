<?php

declare(strict_types=1);

namespace App\Core\Statistics;

use Throwable;

final readonly class FileAccessStatisticsStore implements AccessStatisticsStoreInterface
{
    public function __construct(
        private string $statisticsDir,
        private string $environment,
    ) {
    }

    public function saveLatest(array $snapshot): bool
    {
        $path = $this->path();
        $directory = dirname($path);

        try {
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                return false;
            }

            $encoded = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            $temporaryPath = $path.'.tmp';

            if (false === file_put_contents($temporaryPath, $encoded.PHP_EOL, LOCK_EX)) {
                return false;
            }

            return rename($temporaryPath, $path);
        } catch (Throwable) {
            return false;
        }
    }

    public function latest(): ?array
    {
        $path = $this->path();

        if (!is_file($path)) {
            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function path(): string
    {
        return rtrim($this->statisticsDir, '/').'/'.$this->environment.'/access/latest.json';
    }
}
