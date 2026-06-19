<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Filesystem\PathGuard;
use App\Entity\Extension;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ExtensionLifecycleStore
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PathGuard $pathGuard = new PathGuard(),
    ) {
    }

    public function extension(string $extensionName): ?Extension
    {
        $extension = $this->entityManager->getRepository(Extension::class)->findOneBy([
            'extensionName' => $extensionName,
        ]);

        return $extension instanceof Extension ? $extension : null;
    }

    /**
     * @param list<string> $extensionNames
     *
     * @return list<Extension>
     */
    public function extensionsByName(array $extensionNames): array
    {
        $extensions = [];

        foreach ($extensionNames as $extensionName) {
            $extension = $this->extension($extensionName);

            if ($extension instanceof Extension) {
                $extensions[] = $extension;
            }
        }

        return $extensions;
    }

    /**
     * @return list<Extension>
     */
    public function activeExtensions(): array
    {
        return array_values(array_filter(
            $this->entityManager->getRepository(Extension::class)->findBy(['status' => ExtensionStatus::Active]),
            static fn (mixed $extension): bool => $extension instanceof Extension,
        ));
    }

    /**
     * @return array<string, Extension>
     */
    public function indexedExtensions(): array
    {
        $extensions = [];

        foreach ($this->entityManager->getRepository(Extension::class)->findAll() as $extension) {
            if ($extension instanceof Extension) {
                $extensions[$extension->extensionName()] = $extension;
            }
        }

        return $extensions;
    }

    public function isManagedFilesystemExtension(Extension $extension): bool
    {
        if (!$this->pathGuard->isRelativePath($extension->path())) {
            return false;
        }

        $path = $this->pathGuard->relativePath($extension->path());

        return 'extensions' !== $path && str_starts_with($path, 'extensions/');
    }

    /**
     * @param list<Extension> $extensions
     *
     * @return array<string, ExtensionStatus>
     */
    public function statusSnapshots(array $extensions): array
    {
        $snapshots = [];

        foreach ($extensions as $extension) {
            $snapshots[$extension->extensionName()] = $extension->status();
        }

        return $snapshots;
    }

    /**
     * @param list<string> $extensionNames
     *
     * @return array<string, ExtensionStatus>
     */
    public function statusSnapshotsForNames(array $extensionNames): array
    {
        return $this->statusSnapshots($this->extensionsByName(array_values(array_unique($extensionNames))));
    }

    /**
     * @param array<string, ExtensionStatus> $snapshots
     */
    public function restoreStatuses(array $snapshots): void
    {
        foreach ($snapshots as $extensionName => $status) {
            $extension = $this->extension($extensionName);

            if ($extension instanceof Extension) {
                $extension->restoreStatus($status);
            }
        }
    }
}
