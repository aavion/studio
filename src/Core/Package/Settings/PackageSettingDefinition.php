<?php

declare(strict_types=1);

namespace App\Core\Package\Settings;

use App\Core\Config\ConfigValueType;
use App\Core\Message\MessageException;
use App\Core\Message\MessageKey;
use App\Core\Validation\Identifier;

final readonly class PackageSettingDefinition
{
    /**
     * @param list<string|int|float|bool> $options
     * @param array<string, mixed> $validation
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $packageName,
        private string $key,
        private string $label,
        private mixed $defaultValue = null,
        private ConfigValueType $valueType = ConfigValueType::String,
        private ?string $description = null,
        private array $options = [],
        private ?PackageSettingInputType $inputType = null,
        private array $validation = [],
        private array $metadata = [],
        private int $sortOrder = 0,
    ) {
        $this->assertPackageName($packageName);
        Identifier::assertConfigKey($key, MessageKey::CONFIG_KEY_INVALID);
    }

    public function packageName(): string
    {
        return $this->packageName;
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
     * @return list<string|int|float|bool>
     */
    public function options(): array
    {
        return $this->options;
    }

    public function inputType(): PackageSettingInputType
    {
        return $this->inputType ?? PackageSettingInputType::infer($this->valueType, $this->options);
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
            'package_name' => $this->packageName,
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

    private function assertPackageName(string $packageName): void
    {
        if (1 !== preg_match('/^[a-z0-9][a-z0-9_.\/-]*$/', $packageName)) {
            throw MessageException::invalidArgument(MessageKey::PACKAGE_IDENTIFIER_INVALID, [
                '%identifier%' => $packageName,
            ]);
        }
    }
}
