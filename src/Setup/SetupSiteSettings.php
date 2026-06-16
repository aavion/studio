<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Config\ConfigValueType;
use App\Content\Routing\ContentRouteLocalization;
use App\Core\Statistics\AccessStatisticsPolicy;
use App\Form\FormBuilder;
use App\Form\FormFieldDefinition;
use App\Form\FormInputType;
use App\Security\UserFlowConfig;

final readonly class SetupSiteSettings
{
    /**
     * @return list<FormFieldDefinition>
     */
    public function fields(): array
    {
        return [
            new FormFieldDefinition(
                'registration_mode',
                'setup.form.registration_mode.label',
                UserFlowConfig::REGISTRATION_DISABLED,
                ConfigValueType::String,
                FormInputType::Select,
                options: [
                    UserFlowConfig::REGISTRATION_DISABLED => 'setup.form.registration_mode.options.disabled',
                    UserFlowConfig::REGISTRATION_ADMIN_APPROVAL => 'setup.form.registration_mode.options.admin_approval',
                    UserFlowConfig::REGISTRATION_AUTO_APPROVAL => 'setup.form.registration_mode.options.auto_approval',
                ],
                validation: ['required' => true],
                metadata: ['config_key' => UserFlowConfig::REGISTRATION_MODE_KEY],
                sortOrder: 30,
            ),
            new FormFieldDefinition(
                'username_change_enabled',
                'setup.form.username_change_enabled.label',
                false,
                ConfigValueType::Boolean,
                metadata: ['config_key' => UserFlowConfig::USERNAME_CHANGE_ENABLED_KEY],
                sortOrder: 40,
            ),
            new FormFieldDefinition(
                'route_prefixes_enabled',
                'setup.form.route_prefixes_enabled.label',
                false,
                ConfigValueType::Boolean,
                metadata: ['config_key' => ContentRouteLocalization::ENABLED_KEY],
                sortOrder: 45,
            ),
            new FormFieldDefinition(
                'statistics_enabled',
                'setup.form.statistics_enabled.label',
                true,
                ConfigValueType::Boolean,
                metadata: ['config_key' => AccessStatisticsPolicy::ENABLED_KEY],
                sortOrder: 50,
            ),
            new FormFieldDefinition(
                'statistics_respect_dnt',
                'setup.form.statistics_respect_dnt.label',
                true,
                ConfigValueType::Boolean,
                metadata: ['config_key' => AccessStatisticsPolicy::RESPECT_DO_NOT_TRACK_KEY],
                sortOrder: 60,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $defaults = [];

        foreach ($this->fields() as $field) {
            $defaults[$field->name()] = $field->defaultValue();
        }

        return $defaults;
    }

    /**
     * @return list<string>
     */
    public function booleanFieldNames(): array
    {
        $names = [];

        foreach ($this->fields() as $field) {
            if (ConfigValueType::Boolean === $field->valueType()) {
                $names[] = $field->name();
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, list<string>> $errors
     *
     * @return array<string, mixed>
     */
    public function form(array $values, array $errors = []): array
    {
        return (new FormBuilder())->build(
            'setup-site-settings',
            'setup.site.settings_title',
            $this->fields(),
            array_replace($this->defaults(), $values),
            $errors,
        )->toArray();
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    public function configMap(array $values): array
    {
        $values = array_replace($this->defaults(), $values);
        $config = [];

        foreach ($this->fields() as $field) {
            $key = $field->metadata()['config_key'] ?? null;

            if (!is_string($key) || '' === $key) {
                continue;
            }

            $config[$key] = $values[$field->name()] ?? $field->defaultValue();
        }

        return $config;
    }
}
