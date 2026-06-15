<?php

declare(strict_types=1);

namespace App\Tests\Core\Config;

use App\Api\ApiFeaturePolicy;
use App\Core\Config\Settings\CoreSettingDefinition;
use App\Core\Config\Settings\CoreConfigDefaultProvider;
use App\Core\Config\Settings\CoreSettingsRegistry;
use App\Core\Geo\MaxMindGeoIpConfig;
use App\Core\Log\ConfigAuditLogPolicy;
use App\Core\Statistics\AccessStatisticsPolicy;
use App\Form\FormInputType;
use App\Localization\TranslationLanguageCatalog;
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
            'security.captcha.enabled',
            'security.captcha.provider',
            'security.captcha.preview',
            ConfigAuditLogPolicy::ENABLED_KEY,
            ConfigAuditLogPolicy::EVENTS_KEY,
            MaxMindGeoIpConfig::ENABLED_KEY,
            MaxMindGeoIpConfig::SELECTED_PROVIDER_KEY,
            MaxMindGeoIpConfig::DATABASE_PATH_KEY,
            MaxMindGeoIpConfig::LOCALES_KEY,
            MaxMindGeoIpConfig::UPDATE_ENABLED_KEY,
            MaxMindGeoIpConfig::UPDATE_INTERVAL_KEY,
            MaxMindGeoIpConfig::ACCOUNT_ID_KEY,
            MaxMindGeoIpConfig::LICENSE_KEY_KEY,
        ], array_map(static fn (CoreSettingDefinition $definition): string => $definition->key(), $security));
        self::assertSame(FormInputType::Captcha, $security[2]->formField()->inputType());
        self::assertSame(FormInputType::MultiSelect, $security[4]->formField()->inputType());
        self::assertSame(ConfigAuditLogPolicy::DEFAULT_CATEGORIES, $security[4]->defaultValue());
        self::assertSame(MaxMindGeoIpConfig::DEFAULT_DATABASE_PATH, $security[7]->defaultValue());
        self::assertSame(MaxMindGeoIpConfig::DEFAULT_LOCALES, $security[8]->defaultValue());
        self::assertSame(['sensitive' => true], $security[11]->metadata());
        self::assertSame(['sensitive' => true], $security[12]->metadata());
        self::assertSame(FormInputType::Password, $security[12]->formField()->inputType());

        self::assertSame([
            AccessStatisticsPolicy::ENABLED_KEY,
            AccessStatisticsPolicy::RESPECT_DO_NOT_TRACK_KEY,
        ], array_map(static fn (CoreSettingDefinition $definition): string => $definition->key(), $statistics));
        self::assertTrue($statistics[0]->defaultValue());
        self::assertTrue($statistics[1]->defaultValue());

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
        self::assertSame(MaxMindGeoIpConfig::PROVIDER_KEY, $provider->defaultValue(MaxMindGeoIpConfig::SELECTED_PROVIDER_KEY));
        self::assertSame(MaxMindGeoIpConfig::DEFAULT_DATABASE_PATH, $provider->defaultValue(MaxMindGeoIpConfig::DATABASE_PATH_KEY));
        self::assertFalse($provider->hasDefault('security.captcha.preview'));
        self::assertNull($provider->defaultValue('security.captcha.preview'));
    }

    private function registry(): CoreSettingsRegistry
    {
        $projectDir = dirname(__DIR__, 3);

        return new CoreSettingsRegistry(new TranslationLanguageCatalog($projectDir), new SystemPackageMetadataProvider($projectDir));
    }
}
