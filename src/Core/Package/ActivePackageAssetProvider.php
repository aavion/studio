<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Filesystem\PathGuard;
use App\Entity\ExtensionPackage;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ActivePackageAssetProvider implements ActivePackageAssetProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PathGuard $pathGuard = new PathGuard(),
    ) {
    }

    /**
     * @return list<PackageAssetSyncPackage>
     */
    public function packages(): array
    {
        $packages = [];
        $repository = $this->entityManager->getRepository(ExtensionPackage::class);

        foreach ($repository->findBy(['status' => ExtensionPackageStatus::Active]) as $package) {
            if (!$package instanceof ExtensionPackage || !$this->isRealPackagePath($package->path())) {
                continue;
            }

            $packages[] = new PackageAssetSyncPackage(
                $package->packageName(),
                $package->path(),
                $package->scopes(),
            );
        }

        return $packages;
    }

    private function isRealPackagePath(string $path): bool
    {
        if (!$this->pathGuard->isRelativePath($path)) {
            return false;
        }

        $path = $this->pathGuard->relativePath($path);

        return 'packages' !== $path && str_starts_with($path, 'packages/');
    }
}
