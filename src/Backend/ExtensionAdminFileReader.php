<?php

declare(strict_types=1);

namespace App\Backend;

use App\Core\Filesystem\PathGuard;
use App\Core\Manifest\Manifest;
use App\Core\Manifest\ManifestParser;
use Symfony\Component\HttpKernel\KernelInterface;
use Throwable;

final readonly class ExtensionAdminFileReader
{
    public function __construct(
        private KernelInterface $kernel,
        private ManifestParser $manifestParser = new ManifestParser(),
        private PathGuard $pathGuard = new PathGuard(),
    ) {
    }

    public function readManifest(string $basePath): ?Manifest
    {
        try {
            $path = $this->absolutePath($basePath, '.manifest');
        } catch (Throwable) {
            return null;
        }

        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if (!is_string($contents)) {
            return null;
        }

        $result = $this->manifestParser->parse($contents);
        $manifest = $result->value();

        return $manifest instanceof Manifest ? $manifest : null;
    }

    public function readReadme(string $basePath): ?string
    {
        try {
            $path = $this->absolutePath($basePath, 'README.md');
        } catch (Throwable) {
            return null;
        }

        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return is_string($contents) && '' !== trim($contents) ? $contents : null;
    }

    public function previewImageDataUri(string $basePath, ?string $imagePath): ?string
    {
        if (null === $imagePath || '' === trim($imagePath)) {
            return null;
        }

        try {
            $path = $this->absolutePath($basePath, $imagePath);
        } catch (Throwable) {
            return null;
        }

        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $size = filesize($path);

        if (false === $size || $size > 2_000_000) {
            return null;
        }

        $contents = file_get_contents($path);

        if (!is_string($contents)) {
            return null;
        }

        $mime = $this->imageMimeType($path);

        if (null === $mime) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }

    private function absolutePath(string $basePath, string $relativePath): string
    {
        $basePath = '.' === $basePath ? '' : trim($basePath, '/');
        $relativePath = trim($relativePath, '/');
        $path = '' === $basePath ? $relativePath : $basePath.'/'.$relativePath;

        return rtrim($this->kernel->getProjectDir(), '/').'/'.$this->pathGuard->relativePath($path);
    }

    private function imageMimeType(string $path): ?string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'gif' => 'image/gif',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            default => null,
        };
    }
}
