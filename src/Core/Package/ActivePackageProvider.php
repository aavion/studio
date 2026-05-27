<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Filesystem\PathGuard;
use App\Entity\ExtensionPackage;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ActivePackageProvider implements ActivePackageProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PathGuard $pathGuard = new PathGuard(),
    ) {
    }

    /**
     * @return list<ExtensionPackage>
     */
    public function packages(?PackageScope $scope = null): array
    {
        $packages = [];

        foreach ($this->entityManager->getRepository(ExtensionPackage::class)->findBy(['status' => ExtensionPackageStatus::Active]) as $package) {
            if (
                !$package instanceof ExtensionPackage
                || !$this->isRealPackagePath($package->path())
                || (null !== $scope && !$package->hasScope($scope))
            ) {
                continue;
            }

            $packages[] = $package;
        }

        return $packages;
    }

    public function package(string $packageName): ?ExtensionPackage
    {
        foreach ($this->packages() as $package) {
            if ($package->packageName() === $packageName) {
                return $package;
            }
        }

        return null;
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
