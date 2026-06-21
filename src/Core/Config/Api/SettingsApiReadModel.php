<?php

declare(strict_types=1);

namespace App\Core\Config\Api;

use App\Core\Access\AccessActor;
use App\Core\Access\AccessLevel;
use App\Core\AdminAcl\AdminFeatureAccessPolicy;
use App\Core\Config\Config;
use App\Core\Config\Settings\CoreSettingDefinition;
use App\Core\Config\Settings\CoreSettingsRegistry;

final readonly class SettingsApiReadModel
{
    public function __construct(
        private CoreSettingsRegistry $settings,
        private Config $config,
        private ?AdminFeatureAccessPolicy $adminAcl = null,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sections(?AccessActor $actor = null): array
    {
        $sections = [];
        $actor ??= AccessActor::fromAccess(AccessLevel::ADMIN);

        foreach ($this->settings->allDefinitions() as $definition) {
            if (!$this->definitionVisible($definition, $actor)) {
                continue;
            }

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
    public function settings(?string $section = null, ?AccessActor $actor = null): array
    {
        $resources = [];
        $actor ??= AccessActor::fromAccess(AccessLevel::ADMIN);

        foreach ($this->settings->allDefinitions() as $definition) {
            if (null !== $section && $definition->section() !== $section) {
                continue;
            }

            if (!$this->definitionVisible($definition, $actor)) {
                continue;
            }

            $field = $this->decorateDefinition($definition, $actor)->formField();

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
    public function values(string $section, ?AccessActor $actor = null): array
    {
        $values = [];
        $actor ??= AccessActor::fromAccess(AccessLevel::ADMIN);

        foreach ($this->settings->allDefinitions() as $definition) {
            if ($definition->section() !== $section) {
                continue;
            }

            if (!$this->definitionMutable($definition, $actor)) {
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

    private function decorateDefinition(CoreSettingDefinition $definition, AccessActor $actor): CoreSettingDefinition
    {
        $feature = $definition->metadata()['access_feature'] ?? null;

        if (!is_string($feature) || null === $this->adminAcl) {
            return $definition;
        }

        $state = $this->adminAcl->state($feature, $actor);

        return $definition->withMetadata([
            'access_state' => $state->value,
            'read_only' => !$state->isMutable(),
        ]);
    }

    private function definitionVisible(CoreSettingDefinition $definition, AccessActor $actor): bool
    {
        $feature = $definition->metadata()['access_feature'] ?? null;

        if (is_string($feature) && null !== $this->adminAcl) {
            return $this->adminAcl->isVisible($feature, $actor);
        }

        return $definition->allows($actor);
    }

    private function definitionMutable(CoreSettingDefinition $definition, AccessActor $actor): bool
    {
        $feature = $definition->metadata()['access_feature'] ?? null;

        if (is_string($feature) && null !== $this->adminAcl) {
            return $this->adminAcl->isMutable($feature, $actor);
        }

        return $definition->allows($actor);
    }
}
