<?php

declare(strict_types=1);

namespace App\Core\Config\Settings;

use App\Api\ApiFeaturePolicy;
use App\Core\Access\AccessLevel;
use App\Core\Config\ConfigValueType;
use App\Core\Geo\MaxMindGeoIpConfig;
use App\Core\Log\ConfigAuditLogPolicy;
use App\Core\Log\DatabaseLogRetentionPolicy;
use App\Core\Statistics\AccessStatisticsPolicy;
use App\Form\FormInputType;
use App\Localization\TranslationLanguageCatalog;
use App\Security\Abuse\SuspiciousProbePathMatcher;
use App\Security\AutoBan\AutoBanPolicy;
use App\Security\RateLimit\RateLimitPolicyCatalogue;
use App\Security\RateLimit\RateLimitProfile;
use App\Security\UserFlowConfig;
use App\View\SystemPackageMetadataProvider;

final readonly class CoreSettingsRegistry
{
    public function __construct(
        private TranslationLanguageCatalog $languages,
        private SystemPackageMetadataProvider $systemPackageMetadata,
    ) {
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
    public function allDefinitions(): array
    {
        return [
            new CoreSettingDefinition('general', 'site.title', 'admin.settings.fields.site_title.label', $this->appName(), ConfigValueType::String, validation: ['required' => true, 'max_length' => 120], sortOrder: 10),
            new CoreSettingDefinition('general', 'site.url', 'admin.settings.fields.site_url.label', 'http://localhost', ConfigValueType::String, validation: ['required' => true, 'max_length' => 255], sortOrder: 20),
            new CoreSettingDefinition('general', 'localization.default_language', 'admin.settings.fields.default_language.label', $this->languages->defaultLanguage(), ConfigValueType::String, FormInputType::Select, options: $this->languageOptions(), validation: ['required' => true], sortOrder: 30),
            new CoreSettingDefinition('general', 'localization.route_prefixes_enabled', 'admin.settings.fields.route_prefixes_enabled.label', false, ConfigValueType::Boolean, sortOrder: 40),
            new CoreSettingDefinition('general', 'content.home_path', 'admin.settings.fields.home_path.label', '/home', ConfigValueType::String, validation: ['required' => true, 'pattern' => '^/.*$'], sortOrder: 50),
            new CoreSettingDefinition('general', 'site.footer_copyright', 'admin.settings.fields.footer_copyright.label', '', ConfigValueType::String, FormInputType::Textarea, help: 'admin.settings.fields.footer_copyright.help', validation: ['max_length' => 500], sortOrder: 60),

            new CoreSettingDefinition('dashboard', 'admin.dashboard.widgets', 'admin.settings.fields.dashboard_widgets.label', ['system_status', 'packages', 'recent_activity'], ConfigValueType::Json, FormInputType::MultiSelect, options: [
                'system_status' => 'admin.settings.options.dashboard.system_status',
                'packages' => 'admin.settings.options.dashboard.packages',
                'recent_activity' => 'admin.settings.options.dashboard.recent_activity',
                'setup_warnings' => 'admin.settings.options.dashboard.setup_warnings',
            ], sortOrder: 10),

            new CoreSettingDefinition('users', UserFlowConfig::REGISTRATION_MODE_KEY, 'admin.settings.fields.registration_mode.label', UserFlowConfig::REGISTRATION_DISABLED, ConfigValueType::String, FormInputType::Select, options: [
                UserFlowConfig::REGISTRATION_DISABLED => 'admin.settings.options.registration.disabled',
                UserFlowConfig::REGISTRATION_ADMIN_APPROVAL => 'admin.settings.options.registration.admin_approval',
                UserFlowConfig::REGISTRATION_AUTO_APPROVAL => 'admin.settings.options.registration.auto_approval',
            ], validation: ['required' => true], sortOrder: 10),
            new CoreSettingDefinition('users', UserFlowConfig::DEFAULT_ACL_GROUP_KEY, 'admin.settings.fields.default_acl_group.label', '', ConfigValueType::String, sortOrder: 20),
            new CoreSettingDefinition('users', UserFlowConfig::USERNAME_CHANGE_ENABLED_KEY, 'admin.settings.fields.username_change_enabled.label', false, ConfigValueType::Boolean, sortOrder: 30),
            new CoreSettingDefinition('users', UserFlowConfig::ACCOUNT_LINK_TTL_HOURS_KEY, 'admin.settings.fields.account_link_ttl_hours.label', UserFlowConfig::DEFAULT_ACCOUNT_LINK_TTL_HOURS, ConfigValueType::Integer, FormInputType::Number, help: 'admin.settings.fields.account_link_ttl_hours.help', validation: ['min' => 1, 'max' => 168], sortOrder: 40),
            new CoreSettingDefinition('users', UserFlowConfig::REGISTRATION_ADMIN_NOTIFICATION_EMAIL_KEY, 'admin.settings.fields.registration_admin_notification_email.label', '', ConfigValueType::String, validation: ['max_length' => 180], sortOrder: 50),
            new CoreSettingDefinition('users', UserFlowConfig::SECURITY_NOTIFICATION_EMAIL_KEY, 'admin.settings.fields.security_notification_email.label', '', ConfigValueType::String, validation: ['max_length' => 180], sortOrder: 60),
            new CoreSettingDefinition('users', UserFlowConfig::DELETED_USER_RETENTION_DAYS_KEY, 'admin.settings.fields.deleted_user_retention_days.label', UserFlowConfig::DEFAULT_DELETED_USER_RETENTION_DAYS, ConfigValueType::Integer, FormInputType::Number, help: 'admin.settings.fields.deleted_user_retention_days.help', validation: ['min' => 1, 'max' => 3650], sortOrder: 70),
            new CoreSettingDefinition('users', 'user.menu.enabled', 'admin.settings.fields.user_menu_enabled.label', true, ConfigValueType::Boolean, sortOrder: 80),
            new CoreSettingDefinition('users', 'user.menu.sort_order', 'admin.settings.fields.user_menu_sort_order.label', 900, ConfigValueType::Integer, FormInputType::Number, validation: ['min' => 0, 'max' => 9999], sortOrder: 90),

            new CoreSettingDefinition('mail', 'mail.enabled', 'admin.settings.fields.mail_enabled.label', false, ConfigValueType::Boolean, sortOrder: 10),
            new CoreSettingDefinition('mail', 'mail.from_address', 'admin.settings.fields.mail_from_address.label', 'admin@localhost', ConfigValueType::String, validation: ['max_length' => 180], sortOrder: 20),
            new CoreSettingDefinition('mail', 'mail.from_name', 'admin.settings.fields.mail_from_name.label', $this->appName(), ConfigValueType::String, validation: ['max_length' => 120], sortOrder: 30),

            new CoreSettingDefinition('security', 'security.captcha.enabled', 'admin.settings.fields.captcha_enabled.label', false, ConfigValueType::Boolean, metadata: [
                'access_feature' => 'admin.settings.security',
            ], sortOrder: 10),
            new CoreSettingDefinition('security', 'security.captcha.provider', 'admin.settings.fields.captcha_provider.label', 'none', ConfigValueType::String, FormInputType::Select, options: ['none' => 'admin.settings.options.captcha.none'], validation: ['required' => true], metadata: [
                'access_feature' => 'admin.settings.security',
            ], sortOrder: 20),
            new CoreSettingDefinition('security', 'security.captcha.preview', 'admin.settings.fields.captcha_preview.label', null, ConfigValueType::String, FormInputType::Captcha, metadata: [
                'persist' => false,
                'access_feature' => 'admin.settings.security',
            ], sortOrder: 30),
            new CoreSettingDefinition('security', RateLimitPolicyCatalogue::MODE_KEY, 'admin.settings.fields.rate_limit_mode.label', RateLimitProfile::Standard->value, ConfigValueType::String, FormInputType::Select, options: [
                RateLimitProfile::Off->value => 'admin.settings.options.rate_limit_mode.off',
                RateLimitProfile::Standard->value => 'admin.settings.options.rate_limit_mode.standard',
                RateLimitProfile::Strict->value => 'admin.settings.options.rate_limit_mode.strict',
                RateLimitProfile::Panic->value => 'admin.settings.options.rate_limit_mode.panic',
            ], validation: ['required' => true], metadata: [
                'access_feature' => 'admin.settings.security',
            ], sortOrder: 35),
            new CoreSettingDefinition('security', AutoBanPolicy::ENABLED_KEY, 'admin.settings.fields.auto_ban_enabled.label', AutoBanPolicy::DEFAULT_ENABLED, ConfigValueType::Boolean, help: 'admin.settings.fields.auto_ban_enabled.help', metadata: [
                'access_feature' => 'admin.settings.security',
                'minimum_access_level' => AccessLevel::OWNER,
            ], sortOrder: 36),
            new CoreSettingDefinition('security', AutoBanPolicy::TRUSTED_ACCESS_LEVEL_KEY, 'admin.settings.fields.auto_ban_trusted_access_level.label', AutoBanPolicy::DEFAULT_TRUSTED_ACCESS_LEVEL, ConfigValueType::Integer, FormInputType::Select, help: 'admin.settings.fields.auto_ban_trusted_access_level.help', options: [
                (string) AccessLevel::MANAGER => 'admin.settings.options.access_level.manager',
                (string) AccessLevel::DIRECTOR => 'admin.settings.options.access_level.director',
                (string) AccessLevel::ADMIN => 'admin.settings.options.access_level.admin',
                (string) AccessLevel::OWNER => 'admin.settings.options.access_level.owner',
            ], validation: ['required' => true, 'min' => AccessLevel::MANAGER, 'max' => AccessLevel::OWNER], metadata: [
                'access_feature' => 'admin.settings.security',
                'minimum_access_level' => AccessLevel::OWNER,
            ], sortOrder: 37),
            new CoreSettingDefinition('security', AutoBanPolicy::SCORE_THRESHOLD_KEY, 'admin.settings.fields.auto_ban_score_threshold.label', AutoBanPolicy::DEFAULT_SCORE_THRESHOLD, ConfigValueType::Integer, FormInputType::Number, help: 'admin.settings.fields.auto_ban_score_threshold.help', validation: ['required' => true, 'min' => 2, 'max' => 10000], metadata: [
                'access_feature' => 'admin.settings.security',
                'minimum_access_level' => AccessLevel::OWNER,
            ], sortOrder: 38),
            new CoreSettingDefinition('security', AutoBanPolicy::NEW_BAN_OWNER_ALERTS_KEY, 'admin.settings.fields.auto_ban_new_ban_owner_alerts.label', AutoBanPolicy::DEFAULT_NEW_BAN_OWNER_ALERTS, ConfigValueType::Boolean, help: 'admin.settings.fields.auto_ban_new_ban_owner_alerts.help', metadata: [
                'access_feature' => 'admin.settings.security',
                'minimum_access_level' => AccessLevel::OWNER,
            ], sortOrder: 39),
            new CoreSettingDefinition('security', ConfigAuditLogPolicy::ENABLED_KEY, 'admin.settings.fields.audit_enabled.label', true, ConfigValueType::Boolean, metadata: [
                'access_feature' => 'admin.settings.security',
            ], sortOrder: 40),
            new CoreSettingDefinition('security', ConfigAuditLogPolicy::EVENTS_KEY, 'admin.settings.fields.audit_events.label', ConfigAuditLogPolicy::DEFAULT_CATEGORIES, ConfigValueType::Json, FormInputType::MultiSelect, options: [
                ConfigAuditLogPolicy::CATEGORY_AUTHENTICATION => 'admin.settings.options.audit.authentication',
                ConfigAuditLogPolicy::CATEGORY_BACKEND_ACTIONS => 'admin.settings.options.audit.backend_actions',
                ConfigAuditLogPolicy::CATEGORY_OPERATIONS => 'admin.settings.options.audit.operations',
                ConfigAuditLogPolicy::CATEGORY_PACKAGES => 'admin.settings.options.audit.packages',
                ConfigAuditLogPolicy::CATEGORY_SETTINGS => 'admin.settings.options.audit.settings',
                ConfigAuditLogPolicy::CATEGORY_OTHER => 'admin.settings.options.audit.other',
            ], metadata: [
                'access_feature' => 'admin.settings.security',
            ], sortOrder: 50),
            new CoreSettingDefinition('security', DatabaseLogRetentionPolicy::SECURITY_SIGNAL_RETENTION_DAYS_KEY, 'admin.settings.fields.security_signal_retention_days.label', DatabaseLogRetentionPolicy::DEFAULT_SECURITY_SIGNAL_RETENTION_DAYS, ConfigValueType::Integer, FormInputType::Number, help: 'admin.settings.fields.security_signal_retention_days.help', validation: ['min' => AutoBanPolicy::MAX_TTL_DAYS, 'max' => DatabaseLogRetentionPolicy::MAX_RETENTION_DAYS], metadata: [
                'access_feature' => 'admin.settings.security',
            ], sortOrder: 60),
            new CoreSettingDefinition('security', SuspiciousProbePathMatcher::PATTERNS_KEY, 'admin.settings.fields.security_probe_path_patterns.label', SuspiciousProbePathMatcher::defaultPatternText(), ConfigValueType::String, FormInputType::Textarea, help: 'admin.settings.fields.security_probe_path_patterns.help', validation: ['max_length' => 50000], metadata: [
                'access_feature' => 'admin.settings.security',
            ], sortOrder: 70),

            new CoreSettingDefinition('logging', DatabaseLogRetentionPolicy::MESSAGE_LOG_RETENTION_DAYS_KEY, 'admin.settings.fields.message_log_retention_days.label', DatabaseLogRetentionPolicy::DEFAULT_LOG_RETENTION_DAYS, ConfigValueType::Integer, FormInputType::Number, help: 'admin.settings.fields.message_log_retention_days.help', validation: ['min' => 1, 'max' => DatabaseLogRetentionPolicy::MAX_RETENTION_DAYS], metadata: [
                'access_feature' => 'admin.settings.logging',
            ], sortOrder: 10),
            new CoreSettingDefinition('logging', DatabaseLogRetentionPolicy::AUDIT_LOG_RETENTION_DAYS_KEY, 'admin.settings.fields.audit_log_retention_days.label', DatabaseLogRetentionPolicy::DEFAULT_LOG_RETENTION_DAYS, ConfigValueType::Integer, FormInputType::Number, help: 'admin.settings.fields.audit_log_retention_days.help', validation: ['min' => 1, 'max' => DatabaseLogRetentionPolicy::MAX_RETENTION_DAYS], metadata: [
                'access_feature' => 'admin.settings.logging',
            ], sortOrder: 20),
            new CoreSettingDefinition('logging', DatabaseLogRetentionPolicy::ACCESS_LOG_RETENTION_DAYS_KEY, 'admin.settings.fields.access_log_retention_days.label', DatabaseLogRetentionPolicy::DEFAULT_LOG_RETENTION_DAYS, ConfigValueType::Integer, FormInputType::Number, help: 'admin.settings.fields.access_log_retention_days.help', validation: ['min' => 1, 'max' => DatabaseLogRetentionPolicy::MAX_RETENTION_DAYS], metadata: [
                'access_feature' => 'admin.settings.logging',
            ], sortOrder: 30),
            new CoreSettingDefinition('statistics', AccessStatisticsPolicy::ENABLED_KEY, 'admin.settings.fields.statistics_enabled.label', true, ConfigValueType::Boolean, metadata: [
                'access_feature' => 'admin.settings.statistics',
            ], sortOrder: 10),
            new CoreSettingDefinition('statistics', AccessStatisticsPolicy::RESPECT_DO_NOT_TRACK_KEY, 'admin.settings.fields.statistics_respect_dnt.label', true, ConfigValueType::Boolean, metadata: [
                'access_feature' => 'admin.settings.statistics',
            ], sortOrder: 20),
            new CoreSettingDefinition('statistics', MaxMindGeoIpConfig::ENABLED_KEY, 'admin.settings.fields.geoip_enabled.label', false, ConfigValueType::Boolean, help: 'admin.settings.fields.geoip_enabled.help', metadata: [
                'access_feature' => 'admin.settings.statistics.geoip',
                'access_configurable' => true,
                'minimum_access_level' => AccessLevel::OWNER,
                'help_link_url' => 'https://www.maxmind.com/en/geolite2/signup',
                'help_link_label' => 'admin.settings.fields.geoip_license_link.label',
            ], sortOrder: 30),
            new CoreSettingDefinition('statistics', MaxMindGeoIpConfig::DATABASE_PATH_KEY, 'admin.settings.fields.geoip_database_path.label', MaxMindGeoIpConfig::DEFAULT_DATABASE_PATH, ConfigValueType::String, help: 'admin.settings.fields.geoip_database_path.help', validation: ['required' => true, 'max_length' => 255], metadata: [
                'access_feature' => 'admin.settings.statistics.geoip',
                'access_configurable' => true,
                'minimum_access_level' => AccessLevel::OWNER,
            ], sortOrder: 40),
            new CoreSettingDefinition('statistics', MaxMindGeoIpConfig::LICENSE_KEY_KEY, 'admin.settings.fields.geoip_license_key.label', '', ConfigValueType::String, FormInputType::Password, help: 'admin.settings.fields.geoip_license_key.help', validation: ['max_length' => 180], metadata: [
                'access_feature' => 'admin.settings.statistics.geoip',
                'access_configurable' => true,
                'minimum_access_level' => AccessLevel::OWNER,
                'sensitive' => true,
                'help_link_url' => 'https://www.maxmind.com/en/geolite2/signup',
                'help_link_label' => 'admin.settings.fields.geoip_license_link.label',
            ], sortOrder: 50),

            new CoreSettingDefinition('api', ApiFeaturePolicy::ENABLED_KEY, 'admin.settings.fields.api_enabled.label', true, ConfigValueType::Boolean, help: 'admin.settings.fields.api_enabled.help', metadata: [
                'access_feature' => 'admin.settings.api',
            ], sortOrder: 10),
            new CoreSettingDefinition('api', ApiFeaturePolicy::CORS_ENABLED_KEY, 'admin.settings.fields.api_cors_enabled.label', false, ConfigValueType::Boolean, help: 'admin.settings.fields.api_cors_enabled.help', metadata: [
                'access_feature' => 'admin.settings.api',
            ], sortOrder: 20),
            new CoreSettingDefinition('api', ApiFeaturePolicy::CORS_ALLOWED_ORIGINS_KEY, 'admin.settings.fields.api_cors_allowed_origins.label', [], ConfigValueType::Json, help: 'admin.settings.fields.api_cors_allowed_origins.help', metadata: [
                'access_feature' => 'admin.settings.api',
            ], sortOrder: 30),

            new CoreSettingDefinition('packages', 'packages.update_check_interval', 'admin.settings.fields.package_update_interval.label', 'daily', ConfigValueType::String, FormInputType::Select, options: [
                'manual' => 'admin.settings.options.interval.manual',
                'daily' => 'admin.settings.options.interval.daily',
                'weekly' => 'admin.settings.options.interval.weekly',
            ], validation: ['required' => true], metadata: [
                'access_feature' => 'admin.settings.packages',
            ], sortOrder: 10),
            new CoreSettingDefinition('packages', 'packages.auto_updates.enabled', 'admin.settings.fields.package_auto_updates.label', false, ConfigValueType::Boolean, metadata: [
                'access_feature' => 'admin.settings.packages',
            ], sortOrder: 20),

            new CoreSettingDefinition('scheduler', 'scheduler.enabled', 'admin.settings.fields.scheduler_enabled.label', true, ConfigValueType::Boolean, metadata: [
                'access_feature' => 'admin.settings.scheduler',
            ], sortOrder: 10),
            new CoreSettingDefinition('scheduler', 'scheduler.get_auth_enabled', 'admin.settings.fields.scheduler_get_auth_enabled.label', false, ConfigValueType::Boolean, help: 'admin.settings.fields.scheduler_get_auth_enabled.help', metadata: [
                'access_feature' => 'admin.settings.scheduler',
            ], sortOrder: 20),
            new CoreSettingDefinition('scheduler', 'scheduler.package_action_queues_enabled', 'admin.settings.fields.scheduler_package_action_queues_enabled.label', false, ConfigValueType::Boolean, help: 'admin.settings.fields.scheduler_package_action_queues_enabled.help', metadata: [
                'access_feature' => 'admin.settings.scheduler',
            ], sortOrder: 30),
            new CoreSettingDefinition('scheduler', 'scheduler.web_trigger_enabled', 'admin.settings.fields.scheduler_web_trigger_enabled.label', false, ConfigValueType::Boolean, help: 'admin.settings.fields.scheduler_web_trigger_enabled.help', metadata: [
                'access_feature' => 'admin.settings.scheduler',
            ], sortOrder: 40),
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

    private function appName(): string
    {
        return $this->systemPackageMetadata->metadata()['name'];
    }
}
