<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Validation\IdentifierSpec;
use InvalidArgumentException;

final readonly class ExtensionOperationDefinition
{
    public function __construct(
        private string $identifier,
        private string $labelKey,
        private string $descriptionKey,
        private ?string $target = null,
    ) {
        if (!IdentifierSpec::isMachineIdentifier($identifier, minLength: 3)) {
            throw new InvalidArgumentException(sprintf('Invalid extension operation identifier "%s".', $identifier));
        }

        if (null !== $target && !IdentifierSpec::isMachineIdentifier($target, minLength: 3)) {
            throw new InvalidArgumentException(sprintf('Invalid extension operation target "%s".', $target));
        }
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function labelKey(): string
    {
        return $this->labelKey;
    }

    public function descriptionKey(): string
    {
        return $this->descriptionKey;
    }

    public function target(): string
    {
        return $this->target ?? $this->identifier;
    }
}
