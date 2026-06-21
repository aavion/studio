<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Entity\Extension;

final readonly class ExtensionOperationRegistration
{
    public function __construct(
        private Extension $extension,
        private ExtensionOperationDefinition $definition,
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

    public function definition(): ExtensionOperationDefinition
    {
        return $this->definition;
    }

    public function identifier(): string
    {
        return $this->definition->identifier();
    }

    public function target(): string
    {
        return $this->definition->target();
    }
}
