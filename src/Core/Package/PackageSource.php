<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Manifest\ManifestSpec;
use InvalidArgumentException;

final readonly class PackageSource
{
    private function __construct(
        private string $name,
        private string $relativePath,
        private bool $children,
        private ?ManifestSpec $spec = null,
    ) {
        if ('' === trim($name)) {
            throw new InvalidArgumentException('Package source name must not be empty.');
        }

        if ('' === trim($relativePath)) {
            throw new InvalidArgumentException('Package source path must not be empty.');
        }
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
        $basePath = rtrim($projectDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$this->relativePath;
        $basePath = rtrim($basePath, DIRECTORY_SEPARATOR);

        if (!$this->children) {
            return is_dir($basePath) ? [$basePath] : [];
        }

        if (!is_dir($basePath)) {
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
            if (is_dir($path)) {
                $children[] = $path;
            }
        }

        sort($children);

        return $children;
    }
}
