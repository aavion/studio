<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Filesystem\PathGuard;
use Throwable;

final readonly class ExtensionAssetReader
{
    public const MAX_BYTES = 5242880;

    public function __construct(
        private string $projectDir,
        private PathGuard $pathGuard = new PathGuard(),
    ) {
    }

    public function read(string $extensionName, string $path, bool $private = false): ?string
    {
        if (!ExtensionManifestSpec::isValidSlug($extensionName)) {
            return null;
        }

        try {
            $relativePath = $this->pathGuard->relativePath($path);
            $root = 'extensions/'.$extensionName.'/'.($private ? 'private-assets' : 'assets');
            $assetPath = $root.'/'.$relativePath;
            $absolutePath = $this->absolutePath($assetPath);

            if (!$this->isReadableFile($root, $assetPath, $absolutePath)) {
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

    private function isReadableFile(string $root, string $assetPath, string $absolutePath): bool
    {
        return is_file($absolutePath)
            && !is_link($absolutePath)
            && null === $this->pathGuard->symlinkAncestor($this->projectDir, $assetPath)
            && is_dir($this->absolutePath($root))
            && !is_link($this->absolutePath($root));
    }

    private function absolutePath(string $path): string
    {
        return rtrim($this->projectDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $this->pathGuard->relativePath($path));
    }
}
