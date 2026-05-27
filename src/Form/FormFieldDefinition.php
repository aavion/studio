<?php

declare(strict_types=1);

namespace App\Form;

use App\Core\Config\ConfigValueType;

final readonly class FormFieldDefinition
{
    /**
     * @param array<string, string> $options
     * @param array<string, mixed> $validation
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $name,
        private string $label,
        private mixed $defaultValue = null,
        private ConfigValueType $valueType = ConfigValueType::String,
        private ?FormInputType $inputType = null,
        private ?string $help = null,
        private array $options = [],
        private array $validation = [],
        private array $metadata = [],
        private int $sortOrder = 0,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function defaultValue(): mixed
    {
        return $this->defaultValue;
    }

    public function valueType(): ConfigValueType
    {
        return $this->valueType;
    }

    public function inputType(): FormInputType
    {
        return $this->inputType ?? FormInputType::infer($this->valueType, $this->options);
    }

    public function help(): ?string
    {
        return $this->help;
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        return $this->options;
    }

    /**
     * @return array<string, mixed>
     */
    public function validation(): array
    {
        return $this->validation;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }
}
