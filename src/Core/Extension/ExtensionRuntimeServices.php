<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Extension\Settings\ExtensionSettings;

final readonly class ExtensionRuntimeServices
{
    public function __construct(
        private string $projectDir,
        private ?ExtensionCacheInterface $cache = null,
        private ?ExtensionSettings $settings = null,
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

    public function settings(): ?ExtensionSettings
    {
        return $this->settings;
    }
}
