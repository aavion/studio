<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Filesystem\PathGuard;
use Throwable;

final readonly class ExtensionStorage
{
    public const MAX_BYTES = 5242880;
    public const MAX_LIST_LIMIT = 500;
    public const MAX_TTL_SECONDS = 31_536_000;

    public function __construct(
        private string $projectDir,
        private string $environment,
        private PathGuard $pathGuard = new PathGuard(),
        private ExtensionStorageMetadata $metadata = new ExtensionStorageMetadata(),
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function put(string $extensionName, string $path, string $contents, array $options = []): bool
    {
        $relativePath = $this->storagePath($extensionName, $path);
        if (null === $relativePath || strlen($contents) > self::MAX_BYTES) {
            return false;
        }

        $ttlSeconds = $this->ttlSeconds($options);
        if (false === $ttlSeconds) {
            return false;
        }

        try {
            $root = $this->root($extensionName);
            $target = $this->absolute($root, $relativePath);
            $directory = dirname($target);
            if (!$this->ensureDirectory($directory) || !$this->pathWritable($root, $relativePath, $target)) {
                return false;
            }

            $temporary = $directory.DIRECTORY_SEPARATOR.basename($target).'.tmp.'.bin2hex(random_bytes(8));
            if (false === file_put_contents($temporary, $contents, LOCK_EX)) {
                return false;
            }

            if (!rename($temporary, $target)) {
                @unlink($temporary);

                return false;
            }

            if ($this->metadata->write($root, $relativePath, $ttlSeconds)) {
                return true;
            }

            @unlink($target);

            return false;
        } catch (Throwable) {
            return false;
        }
    }

    public function get(string $extensionName, string $path): ?string
    {
        $relativePath = $this->storagePath($extensionName, $path);
        if (null === $relativePath) {
            return null;
        }

        try {
            $root = $this->root($extensionName);
            if ($this->metadata->expired($root, $relativePath)) {
                $this->delete($extensionName, $relativePath);

                return null;
            }

            $target = $this->absolute($root, $relativePath);
            if (!$this->readableFile($root, $relativePath, $target)) {
                return null;
            }

            if (filesize($target) > self::MAX_BYTES) {
                return null;
            }

            $contents = file_get_contents($target);

            return false === $contents ? null : $contents;
        } catch (Throwable) {
            return null;
        }
    }

    public function delete(string $extensionName, string $path): bool
    {
        $relativePath = $this->storagePath($extensionName, $path);
        if (null === $relativePath) {
            return false;
        }

        try {
            $root = $this->root($extensionName);
            $target = $this->absolute($root, $relativePath);
            $deleted = !is_file($target) || ($this->readableFile($root, $relativePath, $target) && unlink($target));
            $this->metadata->delete($root, $relativePath);

            return $deleted;
        } catch (Throwable) {
            return false;
        }
    }

    public function exists(string $extensionName, string $path): bool
    {
        $relativePath = $this->storagePath($extensionName, $path);
        if (null === $relativePath) {
            return false;
        }

        try {
            $root = $this->root($extensionName);
            if ($this->metadata->expired($root, $relativePath)) {
                $this->delete($extensionName, $relativePath);

                return false;
            }

            $target = $this->absolute($root, $relativePath);

            return $this->readableFile($root, $relativePath, $target);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<array{path: string, size: int, modified_at: int, expires_at: int|null}>
     */
    public function list(string $extensionName, string $prefix = '', array $options = []): array
    {
        if (!ExtensionManifestSpec::isValidSlug($extensionName)) {
            return [];
        }

        try {
            $root = $this->root($extensionName);
            if (!is_dir($root) || is_link($root)) {
                return [];
            }

            $prefix = '' === trim($prefix) ? '' : $this->pathGuard->relativePath($prefix);
            if ('' !== $prefix && $this->metadata->reservedPath($prefix)) {
                return [];
            }

            $limit = $this->listLimit($options);
            $files = [];
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->isLink()) {
                    continue;
                }

                $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                if ($this->metadata->reservedPath($relativePath) || ('' !== $prefix && !str_starts_with($relativePath, rtrim($prefix, '/').'/') && $relativePath !== $prefix)) {
                    continue;
                }

                if ($this->metadata->expired($root, $relativePath)) {
                    $this->delete($extensionName, $relativePath);
                    continue;
                }

                $files[] = [
                    'path' => $relativePath,
                    'size' => (int) $file->getSize(),
                    'modified_at' => (int) $file->getMTime(),
                    'expires_at' => $this->metadata->read($root, $relativePath)['expires_at'] ?? null,
                ];

                if (count($files) >= $limit) {
                    break;
                }
            }

            usort($files, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);

            return $files;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function ttlSeconds(array $options): int|false|null
    {
        if (!array_key_exists('ttl_seconds', $options) && !array_key_exists('ttl', $options)) {
            return null;
        }

        $ttl = $options['ttl_seconds'] ?? $options['ttl'];

        if (!is_int($ttl) && !(is_string($ttl) && ctype_digit($ttl))) {
            return false;
        }

        $ttl = (int) $ttl;

        return $ttl >= 1 && $ttl <= self::MAX_TTL_SECONDS ? $ttl : false;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function listLimit(array $options): int
    {
        $limit = $options['limit'] ?? self::MAX_LIST_LIMIT;
        $limit = is_numeric($limit) ? (int) $limit : self::MAX_LIST_LIMIT;

        return max(1, min(self::MAX_LIST_LIMIT, $limit));
    }

    private function storagePath(string $extensionName, string $path): ?string
    {
        if (!ExtensionManifestSpec::isValidSlug($extensionName)) {
            return null;
        }

        try {
            $path = $this->pathGuard->relativePath($path);

            return $this->metadata->reservedPath($path) ? null : $path;
        } catch (Throwable) {
            return null;
        }
    }

    private function root(string $extensionName): string
    {
        return rtrim($this->projectDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'extensions'.DIRECTORY_SEPARATOR.$this->environment.DIRECTORY_SEPARATOR.$extensionName.DIRECTORY_SEPARATOR.'storage';
    }

    private function absolute(string $root, string $relativePath): string
    {
        return rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    private function ensureDirectory(string $directory): bool
    {
        return (is_dir($directory) || mkdir($directory, 0775, true)) && !is_link($directory);
    }

    private function pathWritable(string $root, string $relativePath, string $target): bool
    {
        return is_dir($root)
            && !is_link($root)
            && !is_link($target)
            && null === $this->pathGuard->symlinkAncestor($root, $relativePath);
    }

    private function readableFile(string $root, string $relativePath, string $target): bool
    {
        return is_file($target)
            && !is_link($target)
            && is_dir($root)
            && !is_link($root)
            && null === $this->pathGuard->symlinkAncestor($root, $relativePath);
    }

}
