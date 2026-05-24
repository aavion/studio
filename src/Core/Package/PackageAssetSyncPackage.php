<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Filesystem\PathGuard;
use InvalidArgumentException;

final readonly class PackageAssetSyncPackage
{
    private string $identifier;

    private string $directory;

    /**
     * @param list<PackageScope> $scopes
     */
    public function __construct(
        string $identifier,
        string $directory,
        private array $scopes,
    ) {
        if ('' === trim($identifier)) {
            throw new InvalidArgumentException('Package asset sync package identifier must not be empty.');
        }

        $pathGuard = new PathGuard();
        $this->identifier = $pathGuard->relativePath($identifier);

        if ('' === trim($directory)) {
            throw new InvalidArgumentException('Package asset sync package directory must not be empty.');
        }

        $this->directory = $pathGuard->relativePath($directory);

        if ([] === $scopes) {
            throw new InvalidArgumentException('Package asset sync package requires at least one scope.');
        }

        foreach ($scopes as $scope) {
            if (!$scope instanceof PackageScope) {
                throw new InvalidArgumentException('Package asset sync package scopes must contain PackageScope instances.');
            }
        }
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * @return list<PackageScope>
     */
    public function scopes(): array
    {
        return $this->scopes;
    }

    public function hasScope(PackageScope $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
