<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Entity\ExtensionPackage;

interface ActivePackageProviderInterface
{
    /**
     * @return list<ExtensionPackage>
     */
    public function packages(?PackageScope $scope = null): array;

    public function package(string $packageName): ?ExtensionPackage;
}
