<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Entity\Extension;

final readonly class ExtensionRuntimeContext
{
    public function __construct(
        private Extension $extension,
        private string $environment,
    ) {
    }

    public function extension(): Extension
    {
        return $this->extension;
    }

    public function extensionName(): string
    {
        return $this->extension->extensionName();
    }

    public function path(): string
    {
        return $this->extension->path();
    }

    public function environment(): string
    {
        return $this->environment;
    }

    /**
     * @return list<ExtensionScope>
     */
    public function scopes(): array
    {
        return $this->extension->scopes();
    }

    public function hasScope(ExtensionScope $scope): bool
    {
        return $this->extension->hasScope($scope);
    }
}
