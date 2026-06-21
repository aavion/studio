<?php

declare(strict_types=1);

namespace App\Core\Extension;

use Throwable;

final readonly class ExtensionStorageMetadata
{
    public const DIRECTORY = '.metadata';

    public function write(string $root, string $path, ?int $ttlSeconds): bool
    {
        $metadataPath = $this->path($root, $path);
        $metadataDirectory = dirname($metadataPath);

        if ((!is_dir($metadataDirectory) && !mkdir($metadataDirectory, 0775, true)) || is_link($metadataDirectory)) {
            return false;
        }

        $metadata = [
            'path' => $path,
            'expires_at' => null === $ttlSeconds ? null : time() + $ttlSeconds,
        ];

        return false !== file_put_contents($metadataPath, json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /**
     * @return array{path?: string, expires_at?: int|null}
     */
    public function read(string $root, string $path): array
    {
        $metadataPath = $this->path($root, $path);
        if (!is_file($metadataPath) || is_link($metadataPath)) {
            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($metadataPath), true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) && ($decoded['path'] ?? null) === $path ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }

    public function expired(string $root, string $path): bool
    {
        $expiresAt = $this->read($root, $path)['expires_at'] ?? null;

        return is_int($expiresAt) && $expiresAt <= time();
    }

    public function delete(string $root, string $path): void
    {
        $metadataPath = $this->path($root, $path);
        if (is_file($metadataPath) && !is_link($metadataPath)) {
            @unlink($metadataPath);
        }
    }

    public function reservedPath(string $path): bool
    {
        return self::DIRECTORY === $path || str_starts_with($path, self::DIRECTORY.'/');
    }

    private function path(string $root, string $path): string
    {
        return rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.self::DIRECTORY.DIRECTORY_SEPARATOR.hash('sha256', $path).'.json';
    }
}
