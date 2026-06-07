<?php

declare(strict_types=1);

namespace App\Content\Api;

final readonly class ContentApiItemPath
{
    public function __construct(
        private string $contentPath,
        private ?string $variant = null,
    ) {
    }

    public function contentPath(): string
    {
        return $this->contentPath;
    }

    public function variant(): ?string
    {
        return $this->variant;
    }
}
