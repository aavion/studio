<?php

declare(strict_types=1);

namespace App\Core\Package;

final readonly class ActivePackageAssetProvider implements ActivePackageAssetProviderInterface
{
    public function __construct(private ActivePackageProviderInterface $activePackageProvider)
    {
    }

    /**
     * @return list<PackageAssetSyncPackage>
     */
    public function packages(): array
    {
        $packages = [];

        foreach ($this->activePackageProvider->packages() as $package) {
            $packages[] = new PackageAssetSyncPackage(
                $package->packageName(),
                $package->path(),
                $package->scopes(),
            );
        }

        return $packages;
    }
}
