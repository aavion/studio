<?php

declare(strict_types=1);

namespace App\Core\Extension;

final readonly class ExtensionRuntimeServices
{
    public function __construct(
        private string $projectDir,
        private ?ExtensionCacheInterface $cache = null,
    ) {
    }

    public function projectDir(): string
    {
        return $this->projectDir;
    }

    public function cache(): ?ExtensionCacheInterface
    {
        return $this->cache;
    }
}
