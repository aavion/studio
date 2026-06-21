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
        private ?ExtensionAssetReader $assets = null,
        private ?ExtensionAssetUrlGenerator $assetUrls = null,
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

    public function assets(): ?ExtensionAssetReader
    {
        return $this->assets;
    }

    public function assetUrls(): ?ExtensionAssetUrlGenerator
    {
        return $this->assetUrls;
    }
}
