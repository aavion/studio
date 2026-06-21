<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Filesystem\PathGuard;
use App\Database\DatabaseReadyState;
use App\Entity\Extension;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ActiveExtensionProvider implements ActiveExtensionProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PathGuard $pathGuard = new PathGuard(),
        private ?DatabaseReadyState $databaseReadyState = null,
    ) {
    }

    /**
     * @return list<Extension>
     */
    public function extensions(?ExtensionScope $scope = null): array
    {
        if (null !== $this->databaseReadyState && !$this->databaseReadyState->isReady()) {
            return [];
        }

        $extensions = [];

        foreach ($this->entityManager->getRepository(Extension::class)->findBy(['status' => ExtensionStatus::Active]) as $extension) {
            if (
                !$extension instanceof Extension
                || !$this->isRealExtensionPath($extension->path())
                || (null !== $scope && !$extension->hasScope($scope))
            ) {
                continue;
            }

            $extensions[] = $extension;
        }

        return $extensions;
    }

    public function extension(string $extensionName): ?Extension
    {
        foreach ($this->extensions() as $extension) {
            if ($extension->extensionName() === $extensionName) {
                return $extension;
            }
        }

        return null;
    }

    private function isRealExtensionPath(string $path): bool
    {
        if (!$this->pathGuard->isRelativePath($path)) {
            return false;
        }

        $path = $this->pathGuard->relativePath($path);

        return 'extensions' !== $path && str_starts_with($path, 'extensions/');
    }
}
