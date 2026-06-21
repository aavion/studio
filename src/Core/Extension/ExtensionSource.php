<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Filesystem\PathGuard;
use App\Core\Manifest\ManifestSpec;
use InvalidArgumentException;

final readonly class ExtensionSource
{
    private string $relativePath;

    private function __construct(
        private string $name,
        string $relativePath,
        private bool $children,
        private ?ManifestSpec $spec = null,
    ) {
        if ('' === trim($name)) {
            throw new InvalidArgumentException('Extension source name must not be empty.');
        }

        if ('' === trim($relativePath)) {
            throw new InvalidArgumentException('Extension source path must not be empty.');
        }

        $relativePath = trim($relativePath);
        $this->relativePath = '.' === $relativePath ? '.' : (new PathGuard())->relativePath($relativePath);
    }

    public static function single(string $name, string $relativePath, ?ManifestSpec $spec = null): self
    {
        return new self($name, $relativePath, false, $spec);
    }

    public static function children(string $name, string $relativePath, ?ManifestSpec $spec = null): self
    {
        return new self($name, $relativePath, true, $spec);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function relativePath(): string
    {
        return $this->relativePath;
    }

    public function spec(): ?ManifestSpec
    {
        return $this->spec;
    }

    /**
     * @return list<string>
     */
    public function candidateDirectories(string $projectDir): array
    {
        $basePath = '.' === $this->relativePath
            ? rtrim($projectDir, DIRECTORY_SEPARATOR)
            : (new PathGuard())->join($projectDir, $this->relativePath);

        if (!$this->children) {
            return is_dir($basePath) && !is_link($basePath) ? [$basePath] : [];
        }

        if (!is_dir($basePath) || is_link($basePath)) {
            return [];
        }

        $children = [];
        $entries = scandir($basePath);
        if (false === $entries) {
            return [];
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $basePath.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path) && !is_link($path)) {
                $children[] = $path;
            }
        }

        sort($children);

        return $children;
    }
}
