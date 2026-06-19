<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Manifest\Manifest;

final readonly class ExtensionCandidate
{
    public function __construct(
        private ExtensionSource $source,
        private string $directory,
        private string $manifestPath,
        private Manifest $manifest,
    ) {
    }

    public function source(): ExtensionSource
    {
        return $this->source;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function manifestPath(): string
    {
        return $this->manifestPath;
    }

    public function manifest(): Manifest
    {
        return $this->manifest;
    }
}
