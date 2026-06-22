<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Filesystem\PathGuard;
use Symfony\Component\Asset\Packages;
use Throwable;

final readonly class ExtensionAssetUrlGenerator
{
    public function __construct(
        private string $projectDir,
        private Packages $assets,
        private PathGuard $pathGuard = new PathGuard(),
    ) {
    }

    public function url(string $extensionName, string $path): ?string
    {
        if (!ExtensionManifestSpec::isValidSlug($extensionName)) {
            return null;
        }

        try {
            $relativePath = $this->pathGuard->relativePath($path);
            $mirrorPath = 'assets/extensions/'.$extensionName.'/'.$relativePath;
            $absolutePath = $this->absolutePath($mirrorPath);

            if (
                !is_file($absolutePath)
                || is_link($absolutePath)
                || null !== $this->pathGuard->symlinkAncestor($this->projectDir, $mirrorPath)
            ) {
                return null;
            }

            return $this->assets->getUrl('extensions/'.$extensionName.'/'.$relativePath);
        } catch (Throwable) {
            return null;
        }
    }

    private function absolutePath(string $path): string
    {
        return rtrim($this->projectDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $this->pathGuard->relativePath($path));
    }
}
