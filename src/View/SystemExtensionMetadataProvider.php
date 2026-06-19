<?php

declare(strict_types=1);

namespace App\View;

use App\Core\Manifest\Manifest;
use App\Core\Manifest\ManifestParser;

final class SystemExtensionMetadataProvider
{
    private ?array $metadata = null;

    public function __construct(
        private readonly string $projectDir,
        private readonly ManifestParser $manifestParser = new ManifestParser(),
    ) {
    }

    /**
     * @return array{identifier: string, name: string, author: string|null, description: string|null, immutable: bool, virtual: bool, scopes: list<string>, version: string|null, date: string|null, channel: string|null, source: string|null, license: string|null, homepage: string|null, image: string|null, manifest: array<string, string>}
     */
    public function metadata(): array
    {
        if (null !== $this->metadata) {
            return $this->metadata;
        }

        $manifest = $this->readRootManifest();

        return $this->metadata = [
            'identifier' => 'system',
            'name' => $this->manifestString($manifest, 'APP_NAME') ?? 'System',
            'author' => $this->manifestString($manifest, 'APP_AUTHOR'),
            'description' => $this->manifestString($manifest, 'APP_DESCRIPTION'),
            'immutable' => true,
            'virtual' => true,
            'scopes' => ['frontend-theme', 'backend-theme', 'system-template'],
            'version' => $manifest->get('APP_VERSION'),
            'date' => $manifest->get('APP_DATE'),
            'channel' => $manifest->get('APP_CHANNEL'),
            'source' => $manifest->get('APP_SOURCE'),
            'license' => $this->manifestString($manifest, 'APP_LICENSE'),
            'homepage' => $this->manifestString($manifest, 'APP_HOMEPAGE'),
            'image' => $this->manifestString($manifest, 'APP_IMAGE'),
            'manifest' => $manifest->all(),
        ];
    }

    private function manifestString(Manifest $manifest, string $key): ?string
    {
        $value = $manifest->get($key);

        return null !== $value && '' !== trim($value) ? $value : null;
    }

    private function readRootManifest(): Manifest
    {
        $path = $this->projectDir.'/.manifest';

        if (!is_file($path) || !is_readable($path)) {
            return new Manifest([]);
        }

        $contents = file_get_contents($path);

        if (!is_string($contents)) {
            return new Manifest([]);
        }

        $result = $this->manifestParser->parse($contents);
        $manifest = $result->value();

        return $manifest instanceof Manifest ? $manifest : new Manifest([]);
    }
}
