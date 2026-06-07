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
    public function settings(): array
    {
        $resources = [];

        foreach ($this->settings->allDefinitions() as $definition) {
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
                    'value' => $this->config->get($field->name(), $field->defaultValue()),
                    'default_value' => $field->defaultValue(),
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
}
