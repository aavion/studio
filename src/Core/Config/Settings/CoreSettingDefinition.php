<?php

declare(strict_types=1);

namespace App\Core\Config\Settings;

use App\Core\Access\AccessActor;
use App\Core\Access\AccessLevel;
use App\Core\Access\AccessRule;
use App\Core\Config\ConfigValueType;
use App\Form\FormFieldDefinition;
use App\Form\FormInputType;

final readonly class CoreSettingDefinition
{
    /**
     * @param array<string, string> $options
     * @param array<string, mixed> $validation
     * @param array<string, mixed> $metadata
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
        private array $metadata = [],
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

    public function valueType(): ConfigValueType
    {
        return $this->valueType;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self(
            $this->section,
            $this->key,
            $this->label,
            $this->defaultValue,
            $this->valueType,
            $this->inputType,
            $this->help,
            $this->options,
            $this->validation,
            [...$this->metadata, ...$metadata],
            $this->sortOrder,
        );
    }

    public function minimumAccessLevel(): int
    {
        $level = $this->metadata['minimum_access_level'] ?? AccessLevel::ADMIN;

        return is_int($level) ? AccessLevel::assert($level) : AccessLevel::ADMIN;
    }

    public function allowsAccessLevel(int $accessLevel): bool
    {
        return $this->allows(AccessActor::fromAccess($accessLevel));
    }

    public function accessRule(): AccessRule
    {
        $groups = $this->metadata['access_groups'] ?? [];

        return AccessRule::from(
            $this->minimumAccessLevel(),
            is_array($groups) ? $groups : [],
        );
    }

    public function allows(AccessActor $actor): bool
    {
        return $this->accessRule()->allows($actor);
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
            ['section' => $this->section, ...$this->metadata],
            $this->sortOrder,
        );
    }
}
