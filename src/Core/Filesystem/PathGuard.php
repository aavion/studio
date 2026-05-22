<?php

declare(strict_types=1);

namespace App\Core\Filesystem;

use InvalidArgumentException;

final class PathGuard
{
    public function relativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        if ('' === $path) {
            throw new InvalidArgumentException('Relative path must not be empty.');
        }

        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('Relative path must not contain null bytes.');
        }

        if ($this->isAbsolute($path)) {
            throw new InvalidArgumentException(sprintf('Path "%s" must be relative.', $path));
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }

            if ('..' === $segment) {
                throw new InvalidArgumentException(sprintf('Path "%s" must stay inside its root.', $path));
            }

            $segments[] = $segment;
        }

        if ([] === $segments) {
            throw new InvalidArgumentException('Relative path must not be empty.');
        }

        return implode('/', $segments);
    }

    public function join(string $root, string $relativePath): string
    {
        return rtrim($root, DIRECTORY_SEPARATOR.'/\\').DIRECTORY_SEPARATOR.$this->relativePath($relativePath);
    }

    public function isRelativePath(string $path): bool
    {
        try {
            $this->relativePath($path);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_starts_with($path, '\\\\')
            || 1 === preg_match('/^[A-Za-z]:\//', $path);
    }
}
