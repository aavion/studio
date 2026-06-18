<?php

declare(strict_types=1);

namespace App\Tests\Core\Config;

use App\Api\ApiFeaturePolicy;
use App\Core\AdminAcl\AdminFeatureDefaults;
use App\Core\AdminAcl\AdminFeatureOverrideStore;
use App\Core\Config\Settings\CoreSettingDefinition;
use App\Core\Config\Settings\CoreConfigDefaultProvider;
use App\Core\Config\Settings\CoreSettingsRegistry;
use App\Core\Log\DatabaseLogRetentionPolicy;
use App\Core\Geo\MaxMindGeoIpConfig;
use App\Core\Log\ConfigAuditLogPolicy;
use App\Core\Statistics\AccessStatisticsPolicy;
use App\Form\FormInputType;
use App\Localization\TranslationLanguageCatalog;
use App\Security\Abuse\SuspiciousProbePathMatcher;
use App\Security\AutoBan\AutoBanPolicy;
use App\Security\RateLimit\RateLimitPolicyCatalogue;
use App\Security\RateLimit\RateLimitProfile;
use App\Security\UserFlowConfig;
use App\View\SystemPackageMetadataProvider;
use PHPUnit\Framework\TestCase;

final class CoreSettingsRegistryTest extends TestCase
{
    public function testItDefinesKnownCoreSettingsForAdminForms(): void
    {
        $registry = $this->registry();

        $general = $registry->definitions('general');
        $users = $registry->definitions('users');
        $security = $registry->definitions('security');
        $logging = $registry->definitions('logging');
        $statistics = $registry->definitions('statistics');
        $api = $registry->definitions('api');

        self::assertSame([
            'site.title',
            'site.url',
            'localization.default_language',
            'localization.route_prefixes_enabled',
            'content.home_path',
            'site.footer_copyright',
        ], array_map(static fn (CoreSettingDefinition $definition): string => $definition->key(), $general));
        self::assertSame(FormInputType::Select, $general[2]->formField()->inputType());
        self::assertSame(['de' => 'de', 'en' => 'en'], $general[2]->formField()->options());
        self::assertSame(['required' => true, 'pattern' => '^/.*$'], $general[4]->formField()->validation());

        self::assertSame([
            UserFlowConfig::REGISTRATION_MODE_KEY,
            UserFlowConfig::DEFAULT_ACL_GROUP_KEY,
            UserFlowConfig::USERNAME_CHANGE_ENABLED_KEY,
            UserFlowConfig::ACCOUNT_LINK_TTL_HOURS_KEY,
            UserFlowConfig::REGISTRATION_ADMIN_NOTIFICATION_EMAIL_KEY,
            UserFlowConfig::SECURITY_NOTIFICATION_EMAIL_KEY,
            UserFlowConfig::DELETED_USER_RETENTION_DAYS_KEY,
            'user.menu.enabled',
            'user.menu.sort_order',
        ], array_map(static fn (CoreSettingDefinition $definition): string => $definition->key(), $users));
        self::assertSame([
            'min' => UserFlowConfig::MIN_ACCOUNT_LINK_TTL_HOURS,
            'max' => UserFlowConfig::MAX_ACCOUNT_LINK_TTL_HOURS,
        ], $users[3]->formField()->validation());
        self::assertSame([
            'min' => UserFlowConfig::MIN_DELETED_USER_RETENTION_DAYS,
            'max' => UserFlowConfig::MAX_DELETED_USER_RETENTION_DAYS,
        ], $users[6]->formField()->validation());
        self::assertSame([
            'min' => UserFlowConfig::MIN_MENU_SORT_ORDER,
            'max' => UserFlowConfig::MAX_MENU_SORT_ORDER,
        ], $users[8]->formField()->validation());

        self::assertSame([
            'security.captcha.enabled',
            'security.captcha.provider',
            'security.captcha.preview',
            RateLimitPolicyCatalogue::MODE_KEY,
            AutoBanPolicy::ENABLED_KEY,
            AutoBanPolicy::TRUSTED_ACCESS_LEVEL_KEY,
            AutoBanPolicy::SCORE_THRESHOLD_KEY,
            AutoBanPolicy::NEW_BAN_OWNER_ALERTS_KEY,
            ConfigAuditLogPolicy::ENABLED_KEY,
            ConfigAuditLogPolicy::EVENTS_KEY,
            DatabaseLogRetentionPolicy::SECURITY_SIGNAL_RETENTION_DAYS_KEY,
            SuspiciousProbePathMatcher::PATTERNS_KEY,
        ], array_map(static fn (CoreSettingDefinition $definition): string => $definition->key(), $security));
        self::assertSame(FormInputType::Captcha, $security[2]->formField()->inputType());
        self::assertSame(FormInputType::Select, $security[3]->formField()->inputType());
        self::assertSame(RateLimitProfile::Standard->value, $security[3]->defaultValue());
        self::assertSame([
            RateLimitProfile::Off->value => 'admin.settings.options.rate_limit_mode.off',
            RateLimitProfile::Standard->value => 'admin.settings.options.rate_limit_mode.standard',
            RateLimitProfile::Strict->value => 'admin.settings.options.rate_limit_mode.strict',
            RateLimitProfile::Panic->value => 'admin.settings.options.rate_limit_mode.panic',
        ], $security[3]->formField()->options());
        self::assertSame('admin.settings.security', $security[3]->metadata()['access_feature']);
        self::assertFalse($security[4]->defaultValue());
        self::assertSame(AutoBanPolicy::DEFAULT_TRUSTED_ACCESS_LEVEL, $security[5]->defaultValue());
        self::assertSame(FormInputType::Select, $security[5]->formField()->inputType());
        self::assertSame(AutoBanPolicy::DEFAULT_SCORE_THRESHOLD, $security[6]->defaultValue());
        self::assertSame([
            'required' => true,
            'min' => AutoBanPolicy::MIN_SCORE_THRESHOLD,
            'max' => AutoBanPolicy::MAX_SCORE_THRESHOLD,
        ], $security[6]->formField()->validation());
        self::assertTrue($security[7]->defaultValue());
        self::assertSame(FormInputType::MultiSelect, $security[9]->formField()->inputType());
        self::assertSame(ConfigAuditLogPolicy::DEFAULT_CATEGORIES, $security[9]->defaultValue());
        self::assertSame(DatabaseLogRetentionPolicy::defaultSecuritySignalRetentionDays(), $security[10]->defaultValue());
        self::assertSame(['min' => AutoBanPolicy::maxTtlDays(), 'max' => DatabaseLogRetentionPolicy::MAX_RETENTION_DAYS], $security[10]->formField()->validation());
        self::assertSame(SuspiciousProbePathMatcher::defaultPatternText(), $security[11]->defaultValue());
        self::assertSame(FormInputType::Textarea, $security[11]->formField()->inputType());

        self::assertSame([
            DatabaseLogRetentionPolicy::MESSAGE_LOG_RETENTION_DAYS_KEY,
            DatabaseLogRetentionPolicy::AUDIT_LOG_RETENTION_DAYS_KEY,
            DatabaseLogRetentionPolicy::ACCESS_LOG_RETENTION_DAYS_KEY,
        ], array_map(static fn (CoreSettingDefinition $definition): string => $definition->key(), $logging));
        self::assertSame(DatabaseLogRetentionPolicy::DEFAULT_LOG_RETENTION_DAYS, $logging[0]->defaultValue());
        self::assertSame(DatabaseLogRetentionPolicy::DEFAULT_LOG_RETENTION_DAYS, $logging[1]->defaultValue());
        self::assertSame(DatabaseLogRetentionPolicy::DEFAULT_LOG_RETENTION_DAYS, $logging[2]->defaultValue());

        self::assertSame([
            AccessStatisticsPolicy::ENABLED_KEY,
            AccessStatisticsPolicy::RESPECT_DO_NOT_TRACK_KEY,
            MaxMindGeoIpConfig::ENABLED_KEY,
            MaxMindGeoIpConfig::DATABASE_PATH_KEY,
            MaxMindGeoIpConfig::LICENSE_KEY_KEY,
        ], array_map(static fn (CoreSettingDefinition $definition): string => $definition->key(), $statistics));
        self::assertTrue($statistics[0]->defaultValue());
        self::assertTrue($statistics[1]->defaultValue());
        self::assertSame(MaxMindGeoIpConfig::DEFAULT_DATABASE_PATH, $statistics[3]->defaultValue());
        self::assertTrue($statistics[4]->metadata()['sensitive']);
        self::assertSame('https://www.maxmind.com/en/geolite2/signup', $statistics[4]->metadata()['help_link_url']);
        self::assertSame(FormInputType::Password, $statistics[4]->formField()->inputType());

        self::assertSame([
            ApiFeaturePolicy::ENABLED_KEY,
            ApiFeaturePolicy::CORS_ENABLED_KEY,
            ApiFeaturePolicy::CORS_ALLOWED_ORIGINS_KEY,
        ], array_map(static fn (CoreSettingDefinition $definition): string => $definition->key(), $api));
        self::assertTrue($api[0]->defaultValue());
        self::assertFalse($api[1]->defaultValue());
        self::assertSame([], $api[2]->defaultValue());
    }

    public function testItKeepsContentEditorSectionsOutOfTheAdminSettingsRegistry(): void
    {
        $registry = $this->registry();

        self::assertSame([], $registry->definitions('content'));
        self::assertSame([], $registry->definitions('schemas'));
        self::assertSame([], $registry->definitions('imports'));
    }

    public function testItExposesPersistedDefaultsForRuntimeConfigFallbacks(): void
    {
        $provider = new CoreConfigDefaultProvider($this->registry());

        self::assertTrue($provider->hasDefault('site.title'));
        self::assertSame('Studio', $provider->defaultValue('site.title'));
        self::assertSame('/home', $provider->defaultValue('content.home_path'));
        self::assertTrue($provider->defaultValue(AccessStatisticsPolicy::ENABLED_KEY));
        self::assertTrue($provider->defaultValue(ApiFeaturePolicy::ENABLED_KEY));
        self::assertFalse($provider->defaultValue(ApiFeaturePolicy::CORS_ENABLED_KEY));
        self::assertSame([], $provider->defaultValue(ApiFeaturePolicy::CORS_ALLOWED_ORIGINS_KEY));
        self::assertFalse($provider->defaultValue(MaxMindGeoIpConfig::ENABLED_KEY));
        self::assertSame(MaxMindGeoIpConfig::DEFAULT_DATABASE_PATH, $provider->defaultValue(MaxMindGeoIpConfig::DATABASE_PATH_KEY));
        self::assertSame(SuspiciousProbePathMatcher::defaultPatternText(), $provider->defaultValue(SuspiciousProbePathMatcher::PATTERNS_KEY));
        self::assertSame(RateLimitProfile::Standard->value, $provider->defaultValue(RateLimitPolicyCatalogue::MODE_KEY));
        self::assertFalse($provider->defaultValue(AutoBanPolicy::ENABLED_KEY));
        self::assertSame(AutoBanPolicy::DEFAULT_TRUSTED_ACCESS_LEVEL, $provider->defaultValue(AutoBanPolicy::TRUSTED_ACCESS_LEVEL_KEY));
        self::assertSame(AutoBanPolicy::DEFAULT_SCORE_THRESHOLD, $provider->defaultValue(AutoBanPolicy::SCORE_THRESHOLD_KEY));
        self::assertTrue($provider->defaultValue(AutoBanPolicy::NEW_BAN_OWNER_ALERTS_KEY));
        self::assertSame((new AdminFeatureDefaults())->overrides(), $provider->defaultValue(AdminFeatureOverrideStore::CONFIG_KEY));
        self::assertFalse($provider->hasDefault('security.captcha.preview'));
        self::assertNull($provider->defaultValue('security.captcha.preview'));
    }

    private function registry(): CoreSettingsRegistry
    {
        $projectDir = dirname(__DIR__, 3);

        return new CoreSettingsRegistry(new TranslationLanguageCatalog($projectDir), new SystemPackageMetadataProvider($projectDir));
    }
}
