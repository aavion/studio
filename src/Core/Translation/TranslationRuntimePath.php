<?php

declare(strict_types=1);

namespace App\Core\Translation;

final readonly class TranslationRuntimePath
{
    private const ROOT = 'translations/runtime';

    private string $environment;

    public function __construct(private string $projectDir, string $environment)
    {
        $this->environment = self::normalizeEnvironment($environment);
    }

    public static function fromGlobals(string $projectDir): self
    {
        return new self($projectDir, (string) ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev'));
    }

    public static function normalizeEnvironment(string $environment): string
    {
        $environment = trim($environment);

        return 1 === preg_match('/^[a-zA-Z0-9_-]+$/', $environment) ? $environment : 'dev';
    }

    public function relativeDirectory(): string
    {
        return self::ROOT.'/'.$this->environment;
    }

    public function relativeCataloguePattern(): string
    {
        return $this->relativeDirectory().'/messages.%s.yaml';
    }

    public function relativeCataloguePath(string $locale): string
    {
        return sprintf($this->relativeCataloguePattern(), $locale);
    }

    public function absoluteCataloguePath(string $locale): string
    {
        return $this->projectDir.'/'.$this->relativeCataloguePath($locale);
    }

    public function relativeManifestPath(): string
    {
        return $this->relativeDirectory().'/.manifest.json';
    }

    public function absoluteManifestPath(): string
    {
        return $this->projectDir.'/'.$this->relativeManifestPath();
    }

    /**
     * @return list<string>
     */
    public function generatedCataloguePaths(): array
    {
        $paths = glob($this->projectDir.'/'.$this->relativeDirectory().'/messages.*.yaml') ?: [];
        sort($paths);

        return $paths;
    }
}
