<?php

declare(strict_types=1);

namespace App\Core\Package;

final readonly class PackageInspection
{
    /**
     * @param list<string> $inventory
     * @param list<string> $templateFiles
     * @param list<string> $assetFiles
     * @param list<string> $phpFiles
     * @param list<string> $sourcePhpFiles
     * @param list<string> $twigFiles
     * @param list<string> $jsonFiles
     * @param list<string> $yamlFiles
     * @param list<string> $cssFiles
     * @param list<string> $javaScriptFiles
     */
    public function __construct(
        private array $inventory,
        private array $templateFiles,
        private array $assetFiles,
        private array $phpFiles,
        private array $sourcePhpFiles,
        private array $twigFiles,
        private array $jsonFiles,
        private array $yamlFiles,
        private array $cssFiles,
        private array $javaScriptFiles,
    ) {
    }

    /**
     * @return list<string>
     */
    public function inventory(): array
    {
        return $this->inventory;
    }

    /**
     * @return list<string>
     */
    public function templateFiles(): array
    {
        return $this->templateFiles;
    }

    /**
     * @return list<string>
     */
    public function assetFiles(): array
    {
        return $this->assetFiles;
    }

    /**
     * @return list<string>
     */
    public function phpFiles(): array
    {
        return $this->phpFiles;
    }

    /**
     * @return list<string>
     */
    public function sourcePhpFiles(): array
    {
        return $this->sourcePhpFiles;
    }

    /**
     * @return list<string>
     */
    public function twigFiles(): array
    {
        return $this->twigFiles;
    }

    /**
     * @return list<string>
     */
    public function jsonFiles(): array
    {
        return $this->jsonFiles;
    }

    /**
     * @return list<string>
     */
    public function yamlFiles(): array
    {
        return $this->yamlFiles;
    }

    /**
     * @return list<string>
     */
    public function cssFiles(): array
    {
        return $this->cssFiles;
    }

    /**
     * @return list<string>
     */
    public function javaScriptFiles(): array
    {
        return $this->javaScriptFiles;
    }

    public function hasTemplates(): bool
    {
        return [] !== $this->templateFiles;
    }

    public function hasAssets(): bool
    {
        return [] !== $this->assetFiles;
    }

    public function hasPhpFiles(): bool
    {
        return [] !== $this->phpFiles;
    }

    public function hasSourcePhpFiles(): bool
    {
        return [] !== $this->sourcePhpFiles;
    }

    public function hasTwigFiles(): bool
    {
        return [] !== $this->twigFiles;
    }

    public function hasJsonFiles(): bool
    {
        return [] !== $this->jsonFiles;
    }

    public function hasYamlFiles(): bool
    {
        return [] !== $this->yamlFiles;
    }

    public function hasCssFiles(): bool
    {
        return [] !== $this->cssFiles;
    }

    public function hasJavaScriptFiles(): bool
    {
        return [] !== $this->javaScriptFiles;
    }
}
