<?php

declare(strict_types=1);

namespace App\Core\Config\Settings;

use App\Core\Config\ConfigValueType;
use App\Form\FormFieldDefinition;
use App\Form\FormInputType;

final readonly class CoreSettingDefinition
{
    /**
     * @param array<string, string> $options
     * @param array<string, mixed> $validation
     */
    public function __construct(
        private string $section,
        private string $key,
        private string $label,
        private mixed $defaultValue,
        private ConfigValueType $valueType,
        private ?FormInputType $inputType = null,
        private ?string $help = null,
        private array $options = [],
        private array $validation = [],
        private int $sortOrder = 0,
    ) {
    }

    public function section(): string
    {
        return $this->section;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function defaultValue(): mixed
    {
        return $this->defaultValue;
    }

    public function formField(): FormFieldDefinition
    {
        return new FormFieldDefinition(
            $this->key,
            $this->label,
            $this->defaultValue,
            $this->valueType,
            $this->inputType,
            $this->help,
            $this->options,
            $this->validation,
            ['section' => $this->section],
            $this->sortOrder,
        );
    }
}
