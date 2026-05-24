<?php

declare(strict_types=1);

namespace App\Core\Package;

interface ActivePackageAssetProviderInterface
{
    /**
     * @return list<PackageAssetSyncPackage>
     */
    public function packages(): array;
}
