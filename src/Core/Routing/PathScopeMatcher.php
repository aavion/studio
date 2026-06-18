<?php

declare(strict_types=1);

namespace App\Core\Routing;

final readonly class PathScopeMatcher
{
    public function matchesPrefix(string $path, string $prefix): bool
    {
        $prefixSegments = $this->segments($prefix);
        if ([] === $prefixSegments) {
            return '/' === $path;
        }

        return $this->matchesSegments($path, ...$prefixSegments);
    }

    public function matchesAnyPrefix(string $path, string ...$prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($this->matchesPrefix($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function matchesSegments(string $path, string ...$segments): bool
    {
        $pathSegments = $this->segments($path);
        foreach ($segments as $index => $segment) {
            if (($pathSegments[$index] ?? null) !== trim($segment, '/')) {
                return false;
            }
        }

        return [] !== $segments;
    }

    /**
     * @return list<string>
     */
    private function segments(string $path): array
    {
        return array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn (string $segment): bool => '' !== $segment,
        ));
    }
}
