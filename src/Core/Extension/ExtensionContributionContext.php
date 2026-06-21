<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Entity\Extension;

final readonly class ExtensionContributionContext
{
    public function __construct(
        private Extension $extension,
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
