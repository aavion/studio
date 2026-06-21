<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Filesystem\PathGuard;
use Throwable;

final readonly class ExtensionFileReader
{
    public const MAX_BYTES = 5242880;

    public function __construct(
        private string $projectDir,
        private PathGuard $pathGuard = new PathGuard(),
    ) {
    }

    public function read(string $extensionName, string $path): ?string
    {
        if (!ExtensionManifestSpec::isValidSlug($extensionName)) {
            return null;
        }

        try {
            $relativePath = $this->pathGuard->relativePath($path);
            $root = 'extensions/'.$extensionName;
            $filePath = $root.'/'.$relativePath;
            $absolutePath = $this->absolutePath($filePath);

            if (!$this->isReadableFile($root, $filePath, $absolutePath)) {
                return null;
            }

            if (filesize($absolutePath) > self::MAX_BYTES) {
                return null;
            }

            $contents = file_get_contents($absolutePath);

            return false === $contents ? null : $contents;
        } catch (Throwable) {
            return null;
        }
    }

    private function isReadableFile(string $root, string $filePath, string $absolutePath): bool
    {
        return is_file($absolutePath)
            && !is_link($absolutePath)
            && is_dir($this->absolutePath($root))
            && !is_link($this->absolutePath($root))
            && null === $this->pathGuard->symlinkAncestor($this->projectDir, $filePath);
    }

    private function absolutePath(string $path): string
    {
        return rtrim($this->projectDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $this->pathGuard->relativePath($path));
    }
}
