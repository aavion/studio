<?php

declare(strict_types=1);

namespace App\Form;

final readonly class FormSubmissionResult
{
    /**
     * @param array<string, mixed> $values
     * @param array<string, list<string>> $errors
     */
    public function __construct(
        private array $values = [],
        private array $errors = [],
    ) {
    }

    public function isValid(): bool
    {
        return [] === $this->errors;
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return $this->values;
    }

    public function value(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
