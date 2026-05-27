<?php

declare(strict_types=1);

namespace App\Core\Config\Settings;

use App\Core\Config\ConfigValueType;
use App\Core\Log\ConfigAuditLogPolicy;
use App\Form\FormInputType;
use App\Localization\TranslationLanguageCatalog;

final readonly class CoreSettingsRegistry
{
    public function __construct(private TranslationLanguageCatalog $languages)
    {
    }

    /**
     * @return list<CoreSettingDefinition>
     */
    public function definitions(string $section): array
    {
        return array_values(array_filter(
            $this->allDefinitions(),
            static fn (CoreSettingDefinition $definition): bool => $definition->section() === $section,
        ));
    }

    /**
     * @return list<CoreSettingDefinition>
     */
    private function allDefinitions(): array
    {
        return [
            new CoreSettingDefinition('general', 'site.title', 'admin.settings.fields.site_title.label', 'aavion Studio', ConfigValueType::String, validation: ['required' => true, 'max_length' => 120], sortOrder: 10),
            new CoreSettingDefinition('general', 'site.url', 'admin.settings.fields.site_url.label', 'http://localhost', ConfigValueType::String, validation: ['required' => true, 'max_length' => 255], sortOrder: 20),
            new CoreSettingDefinition('general', 'localization.default_language', 'admin.settings.fields.default_language.label', $this->languages->defaultLanguage(), ConfigValueType::String, FormInputType::Select, options: $this->languageOptions(), validation: ['required' => true], sortOrder: 30),
            new CoreSettingDefinition('general', 'localization.route_prefixes_enabled', 'admin.settings.fields.route_prefixes_enabled.label', false, ConfigValueType::Boolean, sortOrder: 40),
            new CoreSettingDefinition('general', 'content.home_path', 'admin.settings.fields.home_path.label', '/home', ConfigValueType::String, validation: ['required' => true, 'pattern' => '^/.*$'], sortOrder: 50),

            new CoreSettingDefinition('dashboard', 'admin.dashboard.widgets', 'admin.settings.fields.dashboard_widgets.label', ['system_status', 'packages', 'recent_activity'], ConfigValueType::Json, FormInputType::MultiSelect, options: [
                'system_status' => 'admin.settings.options.dashboard.system_status',
                'packages' => 'admin.settings.options.dashboard.packages',
                'recent_activity' => 'admin.settings.options.dashboard.recent_activity',
                'setup_warnings' => 'admin.settings.options.dashboard.setup_warnings',
            ], sortOrder: 10),

            new CoreSettingDefinition('users', 'user.registration.enabled', 'admin.settings.fields.registration_enabled.label', false, ConfigValueType::Boolean, sortOrder: 10),
            new CoreSettingDefinition('users', 'user.default_acl_group', 'admin.settings.fields.default_acl_group.label', 'registered', ConfigValueType::String, validation: ['required' => true], sortOrder: 20),
            new CoreSettingDefinition('users', 'user.menu.enabled', 'admin.settings.fields.user_menu_enabled.label', true, ConfigValueType::Boolean, sortOrder: 30),
            new CoreSettingDefinition('users', 'user.menu.sort_order', 'admin.settings.fields.user_menu_sort_order.label', 900, ConfigValueType::Integer, FormInputType::Number, validation: ['min' => 0, 'max' => 9999], sortOrder: 40),

            new CoreSettingDefinition('mail', 'mail.enabled', 'admin.settings.fields.mail_enabled.label', false, ConfigValueType::Boolean, sortOrder: 10),
            new CoreSettingDefinition('mail', 'mail.from_address', 'admin.settings.fields.mail_from_address.label', 'admin@localhost', ConfigValueType::String, validation: ['max_length' => 180], sortOrder: 20),
            new CoreSettingDefinition('mail', 'mail.from_name', 'admin.settings.fields.mail_from_name.label', 'aavion Studio', ConfigValueType::String, validation: ['max_length' => 120], sortOrder: 30),

            new CoreSettingDefinition('security', 'security.captcha.enabled', 'admin.settings.fields.captcha_enabled.label', false, ConfigValueType::Boolean, sortOrder: 10),
            new CoreSettingDefinition('security', 'security.captcha.provider', 'admin.settings.fields.captcha_provider.label', 'none', ConfigValueType::String, FormInputType::Select, options: ['none' => 'admin.settings.options.captcha.none'], validation: ['required' => true], sortOrder: 20),
            new CoreSettingDefinition('security', 'security.captcha.preview', 'admin.settings.fields.captcha_preview.label', null, ConfigValueType::String, FormInputType::Captcha, metadata: ['persist' => false], sortOrder: 30),
            new CoreSettingDefinition('security', ConfigAuditLogPolicy::ENABLED_KEY, 'admin.settings.fields.audit_enabled.label', true, ConfigValueType::Boolean, sortOrder: 40),
            new CoreSettingDefinition('security', ConfigAuditLogPolicy::EVENTS_KEY, 'admin.settings.fields.audit_events.label', ConfigAuditLogPolicy::DEFAULT_CATEGORIES, ConfigValueType::Json, FormInputType::MultiSelect, options: [
                ConfigAuditLogPolicy::CATEGORY_AUTHENTICATION => 'admin.settings.options.audit.authentication',
                ConfigAuditLogPolicy::CATEGORY_BACKEND_ACTIONS => 'admin.settings.options.audit.backend_actions',
                ConfigAuditLogPolicy::CATEGORY_OPERATIONS => 'admin.settings.options.audit.operations',
                ConfigAuditLogPolicy::CATEGORY_PACKAGES => 'admin.settings.options.audit.packages',
                ConfigAuditLogPolicy::CATEGORY_SETTINGS => 'admin.settings.options.audit.settings',
                ConfigAuditLogPolicy::CATEGORY_OTHER => 'admin.settings.options.audit.other',
            ], sortOrder: 50),

            new CoreSettingDefinition('packages', 'packages.update_check_interval', 'admin.settings.fields.package_update_interval.label', 'daily', ConfigValueType::String, FormInputType::Select, options: [
                'manual' => 'admin.settings.options.interval.manual',
                'daily' => 'admin.settings.options.interval.daily',
                'weekly' => 'admin.settings.options.interval.weekly',
            ], validation: ['required' => true], sortOrder: 10),
            new CoreSettingDefinition('packages', 'packages.auto_updates.enabled', 'admin.settings.fields.package_auto_updates.label', false, ConfigValueType::Boolean, sortOrder: 20),

            new CoreSettingDefinition('scheduler', 'scheduler.enabled', 'admin.settings.fields.scheduler_enabled.label', false, ConfigValueType::Boolean, sortOrder: 10),
            new CoreSettingDefinition('scheduler', 'scheduler.default_interval', 'admin.settings.fields.scheduler_default_interval.label', 'hourly', ConfigValueType::String, FormInputType::Select, options: [
                'hourly' => 'admin.settings.options.interval.hourly',
                'daily' => 'admin.settings.options.interval.daily',
                'weekly' => 'admin.settings.options.interval.weekly',
            ], validation: ['required' => true], sortOrder: 20),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function languageOptions(): array
    {
        $options = [];

        foreach ($this->languages->availableLanguages() as $language) {
            $options[$language] = $language;
        }

        return $options;
    }
}
