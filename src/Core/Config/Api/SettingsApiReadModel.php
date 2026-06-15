<?php

declare(strict_types=1);

namespace App\Core\Config\Api;

use App\Core\Config\Config;
use App\Core\Config\Settings\CoreSettingsRegistry;

final readonly class SettingsApiReadModel
{
    public function __construct(
        private CoreSettingsRegistry $settings,
        private Config $config,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sections(): array
    {
        $sections = [];

        foreach ($this->settings->allDefinitions() as $definition) {
            $field = $definition->formField();
            if (false === ($field->metadata()['persist'] ?? true)) {
                continue;
            }

            $section = $definition->section();
            $sections[$section] ??= [
                'type' => 'settings_section',
                'id' => $section,
                'attributes' => [
                    'section' => $section,
                    'path' => '/api/v1/admin/settings/'.$section,
                    'field_count' => 0,
                    'title_key' => 'admin.settings.'.$section.'.title',
                ],
            ];
            ++$sections[$section]['attributes']['field_count'];
        }

        return array_values($sections);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function settings(?string $section = null): array
    {
        $resources = [];

        foreach ($this->settings->allDefinitions() as $definition) {
            if (null !== $section && $definition->section() !== $section) {
                continue;
            }

            $field = $definition->formField();

            if (false === ($field->metadata()['persist'] ?? true)) {
                continue;
            }

            $resources[] = [
                'type' => 'setting',
                'id' => $field->name(),
                'attributes' => [
                    'section' => $definition->section(),
                    'key' => $field->name(),
                    'value' => $this->apiValue($field->metadata(), $this->config->get($field->name(), $field->defaultValue())),
                    'default_value' => $this->apiValue($field->metadata(), $field->defaultValue()),
                    'value_type' => $field->valueType()->value,
                    'input_type' => $field->inputType()->value,
                    'label_key' => $field->label(),
                    'help_key' => $field->help(),
                    'options' => $field->options(),
                    'validation' => $field->validation(),
                    'metadata' => $field->metadata(),
                    'sort_order' => $field->sortOrder(),
                ],
            ];
        }

        return $resources;
    }

    /**
     * @return array<string, mixed>
     */
    public function values(string $section): array
    {
        $values = [];

        foreach ($this->settings->allDefinitions() as $definition) {
            if ($definition->section() !== $section) {
                continue;
            }

            $field = $definition->formField();
            if (false === ($field->metadata()['persist'] ?? true)) {
                continue;
            }

            $values[$field->name()] = true === ($field->metadata()['sensitive'] ?? false)
                ? ''
                : $this->config->get($field->name(), $field->defaultValue());
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function apiValue(array $metadata, mixed $value): mixed
    {
        if (true !== ($metadata['sensitive'] ?? false)) {
            return $value;
        }

        return is_string($value) && '' !== trim($value) ? '[protected]' : '';
    }
}
