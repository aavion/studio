<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Filesystem\PathGuard;
use InvalidArgumentException;

final readonly class ExtensionAssetSyncTarget
{
    private string $identifier;

    private string $directory;

    /**
     * @param list<ExtensionScope> $scopes
     */
    public function __construct(
        string $identifier,
        string $directory,
        private array $scopes,
    ) {
        if ('' === trim($identifier)) {
            throw new InvalidArgumentException('Extension asset sync target identifier must not be empty.');
        }

        $pathGuard = new PathGuard();
        $this->identifier = $pathGuard->relativePath($identifier);

        if ('' === trim($directory)) {
            throw new InvalidArgumentException('Extension asset sync target directory must not be empty.');
        }

        $this->directory = $pathGuard->relativePath($directory);

        if ([] === $scopes) {
            throw new InvalidArgumentException('Extension asset sync target requires at least one scope.');
        }

        foreach ($scopes as $scope) {
            if (!$scope instanceof ExtensionScope) {
                throw new InvalidArgumentException('Extension asset sync target scopes must contain ExtensionScope instances.');
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
     * @return list<ExtensionScope>
     */
    public function scopes(): array
    {
        return $this->scopes;
    }

    public function hasScope(ExtensionScope $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
