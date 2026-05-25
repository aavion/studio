<?php

declare(strict_types=1);

namespace App\Core\Package;

final readonly class PackageAssetRegistryWriter
{
    private const CSS_REGISTRIES = [
        PackageAssetRegistryBuilder::BUCKET_EXTENSION => 'assets/styles/packages/extension.css',
        PackageAssetRegistryBuilder::BUCKET_FRONTEND_THEME => 'assets/styles/packages/frontend-theme.css',
        PackageAssetRegistryBuilder::BUCKET_BACKEND_THEME => 'assets/styles/packages/backend-theme.css',
    ];

    private const JAVASCRIPT_REGISTRIES = [
        PackageAssetRegistryBuilder::BUCKET_EXTENSION => 'assets/js/packages/extension.js',
        PackageAssetRegistryBuilder::BUCKET_FRONTEND_THEME => 'assets/js/packages/frontend-theme.js',
        PackageAssetRegistryBuilder::BUCKET_BACKEND_THEME => 'assets/js/packages/backend-theme.js',
    ];

    public function __construct(
        private PackageAssetFilesystem $filesystem,
        private PackageAssetRegistryBuilder $registryBuilder = new PackageAssetRegistryBuilder(),
    ) {
    }

    /**
     * @param list<PackageAssetContribution> $contributions
     */
    public function write(array $contributions): void
    {
        foreach (self::CSS_REGISTRIES as $bucket => $path) {
            $this->filesystem->writeFile($path, $this->registryBuilder->buildCssRegistry($contributions, $bucket));
        }

        foreach (self::JAVASCRIPT_REGISTRIES as $bucket => $path) {
            $this->filesystem->writeFile($path, $this->registryBuilder->buildJavaScriptRegistry($contributions, $bucket));
        }
    }
}
