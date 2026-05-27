<?php

declare(strict_types=1);

namespace App\Setup;

final readonly class SetupWebInputResult
{
    /**
     * @param array<string, mixed> $values
     * @param array<string, list<string>> $errors
     */
    public function __construct(
        private array $values,
        private ?SetupInput $input = null,
        private array $errors = [],
    ) {
    }

    public function isValid(): bool
    {
        return null !== $this->input && [] === $this->errors;
    }

    public function input(): ?SetupInput
    {
        return $this->input;
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
