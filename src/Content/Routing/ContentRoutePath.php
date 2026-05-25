<?php

declare(strict_types=1);

namespace App\Content\Routing;

final readonly class ContentRoutePath
{
    private function __construct(
        private string $path,
        private ?string $variant,
    ) {
    }

    public static function fromPath(string $path): self
    {
        $path = ContentPathLookup::normalizePath($path);
        $segments = array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn (string $segment): bool => '' !== $segment,
        ));
        $variant = null;
        $lastSegment = [] === $segments ? null : $segments[array_key_last($segments)];

        if (is_string($lastSegment) && str_starts_with($lastSegment, '~')) {
            $variant = substr($lastSegment, 1);
            array_pop($segments);
        }

        $contentPath = [] === $segments ? '/' : '/'.implode('/', $segments);

        return new self($contentPath, $variant);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function variant(): ?string
    {
        return $this->variant;
    }
}
