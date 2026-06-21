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
        private ?ExtensionFileReader $files = null,
        private ?ExtensionAssetReader $assets = null,
        private ?ExtensionAssetUrlGenerator $assetUrls = null,
        private ?ExtensionEndpointUrlGenerator $endpointUrls = null,
        private ?ExtensionHttpRequest $httpRequests = null,
        private ?ExtensionLogFacade $logs = null,
        private ?ExtensionAlertFacade $alerts = null,
        private ?ExtensionReferenceFacade $references = null,
        private ?ExtensionDatabaseFacade $databases = null,
        private ?ExtensionMailFacade $mail = null,
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

    public function files(): ?ExtensionFileReader
    {
        return $this->files;
    }

    public function assets(): ?ExtensionAssetReader
    {
        return $this->assets;
    }

    public function assetUrls(): ?ExtensionAssetUrlGenerator
    {
        return $this->assetUrls;
    }

    public function endpointUrls(): ?ExtensionEndpointUrlGenerator
    {
        return $this->endpointUrls;
    }

    public function httpRequests(): ?ExtensionHttpRequest
    {
        return $this->httpRequests;
    }

    public function logs(): ?ExtensionLogFacade
    {
        return $this->logs;
    }

    public function alerts(): ?ExtensionAlertFacade
    {
        return $this->alerts;
    }

    public function references(): ?ExtensionReferenceFacade
    {
        return $this->references;
    }

    public function databases(): ?ExtensionDatabaseFacade
    {
        return $this->databases;
    }

    public function mail(): ?ExtensionMailFacade
    {
        return $this->mail;
    }
}
