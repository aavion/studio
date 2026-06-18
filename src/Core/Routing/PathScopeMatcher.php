<?php

declare(strict_types=1);

namespace App\Core\Routing;

final readonly class PathScopeMatcher
{
    public function matchesPrefix(string $path, string $prefix): bool
    {
        $prefix = $this->normalizedPrefix($prefix);

        return $path === $prefix || str_starts_with($path, $prefix.'/');
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

    private function normalizedPrefix(string $prefix): string
    {
        $prefix = '/'.trim($prefix, '/');

        return '//' === $prefix ? '/' : $prefix;
    }
}
