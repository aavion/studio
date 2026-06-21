<?php

declare(strict_types=1);

namespace App\Core\Extension\Settings;

use App\Core\Config\ConfigMessageKey;
use App\Core\Config\ConfigValueType;
use App\Core\Message\MessageException;
use App\Core\Extension\ExtensionIdentity;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Validation\Identifier;
use App\Form\FormFieldDefinition;
use App\Form\FormInputType;

final readonly class ExtensionSettingDefinition
{
    /**
     * @param array<string, string>|list<string|int|float|bool> $options
     * @param array<string, mixed> $validation
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $extensionName,
        private string $key,
        private string $label,
        private mixed $defaultValue = null,
        private ConfigValueType $valueType = ConfigValueType::String,
        private ?string $description = null,
        private array $options = [],
        private ?FormInputType $inputType = null,
        private array $validation = [],
        private array $metadata = [],
        private int $sortOrder = 0,
    ) {
        $this->assertExtensionName($extensionName);
        Identifier::assertConfigKey($key, ConfigMessageKey::CONFIG_KEY_INVALID);
    }

    public function extensionName(): string
    {
        return $this->extensionName;
    }

    public function key(): string
    {
        return $this->key;
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

    public function description(): ?string
    {
        return $this->description;
    }

    /**
     * @return array<string, string>|list<string|int|float|bool>
     */
    public function options(): array
    {
        return $this->options;
    }

    public function inputType(): FormInputType
    {
        return $this->inputType ?? FormInputType::infer($this->valueType, $this->optionLabels());
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

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $value = null): array
    {
        return [
            'extension_name' => $this->extensionName,
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'type' => $this->valueType->value,
            'input_type' => $this->inputType()->value,
            'value' => $value ?? $this->defaultValue,
            'default' => $this->defaultValue,
            'options' => $this->options,
            'validation' => $this->validation,
            'metadata' => $this->metadata,
            'sort_order' => $this->sortOrder,
        ];
    }

    public function formField(mixed $value = null): FormFieldDefinition
    {
        return new FormFieldDefinition(
            $this->key,
            $this->label,
            $value ?? $this->defaultValue,
            $this->valueType,
            $this->inputType(),
            $this->description,
            $this->optionLabels(),
            $this->validation,
            ['extension_name' => $this->extensionName, ...$this->metadata],
            $this->sortOrder,
        );
    }

    /**
     * @return array<string, string>
     */
    private function optionLabels(): array
    {
        $options = [];

        foreach ($this->options as $key => $option) {
            if (is_string($key)) {
                $options[$key] = (string) $option;

                continue;
            }

            $options[(string) $option] = (string) $option;
        }

        return $options;
    }

    private function assertExtensionName(string $extensionName): void
    {
        if (!ExtensionIdentity::isExtensionName($extensionName)) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_IDENTIFIER_INVALID, [
                '%identifier%' => $extensionName,
            ]);
        }
    }
}
