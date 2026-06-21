<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Message\MessageException;
use App\Core\Validation\IdentifierSpec;

final readonly class ExtensionOperationDefinition
{
    public function __construct(
        private string $identifier,
        private string $labelKey,
        private string $descriptionKey,
        private ?string $target = null,
    ) {
        if (!IdentifierSpec::isMachineIdentifier($identifier, minLength: 3)) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_OPERATION_IDENTIFIER_INVALID, [
                '%identifier%' => $identifier,
            ], [
                'identifier' => $identifier,
            ]);
        }

        if (null !== $target && !IdentifierSpec::isMachineIdentifier($target, minLength: 3)) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_OPERATION_TARGET_INVALID, [
                '%target%' => $target,
            ], [
                'target' => $target,
            ]);
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
