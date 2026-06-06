<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Filesystem\PathGuard;
use App\Entity\ExtensionPackage;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PackageLifecycleStore
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PathGuard $pathGuard = new PathGuard(),
    ) {
    }

    public function package(string $packageName): ?ExtensionPackage
    {
        $package = $this->entityManager->getRepository(ExtensionPackage::class)->findOneBy([
            'packageName' => $packageName,
        ]);

        return $package instanceof ExtensionPackage ? $package : null;
    }

    /**
     * @param list<string> $packageNames
     *
     * @return list<ExtensionPackage>
     */
    public function packagesByName(array $packageNames): array
    {
        $packages = [];

        foreach ($packageNames as $packageName) {
            $package = $this->package($packageName);

            if ($package instanceof ExtensionPackage) {
                $packages[] = $package;
            }
        }

        return $packages;
    }

    /**
     * @return list<ExtensionPackage>
     */
    public function activePackages(): array
    {
        return array_values(array_filter(
            $this->entityManager->getRepository(ExtensionPackage::class)->findBy(['status' => ExtensionPackageStatus::Active]),
            static fn (mixed $package): bool => $package instanceof ExtensionPackage,
        ));
    }

    /**
     * @return array<string, ExtensionPackage>
     */
    public function indexedPackages(): array
    {
        $packages = [];

        foreach ($this->entityManager->getRepository(ExtensionPackage::class)->findAll() as $package) {
            if ($package instanceof ExtensionPackage) {
                $packages[$package->packageName()] = $package;
            }
        }

        return $packages;
    }

    public function isManagedFilesystemPackage(ExtensionPackage $package): bool
    {
        if (!$this->pathGuard->isRelativePath($package->path())) {
            return false;
        }

        $path = $this->pathGuard->relativePath($package->path());

        return 'packages' !== $path && str_starts_with($path, 'packages/');
    }

    /**
     * @param list<ExtensionPackage> $packages
     *
     * @return array<string, ExtensionPackageStatus>
     */
    public function statusSnapshots(array $packages): array
    {
        $snapshots = [];

        foreach ($packages as $package) {
            $snapshots[$package->packageName()] = $package->status();
        }

        return $snapshots;
    }

    /**
     * @param list<string> $packageNames
     *
     * @return array<string, ExtensionPackageStatus>
     */
    public function statusSnapshotsForNames(array $packageNames): array
    {
        return $this->statusSnapshots($this->packagesByName(array_values(array_unique($packageNames))));
    }

    /**
     * @param array<string, ExtensionPackageStatus> $snapshots
     */
    public function restoreStatuses(array $snapshots): void
    {
        foreach ($snapshots as $packageName => $status) {
            $package = $this->package($packageName);

            if ($package instanceof ExtensionPackage) {
                $package->restoreStatus($status);
            }
        }
    }
}
