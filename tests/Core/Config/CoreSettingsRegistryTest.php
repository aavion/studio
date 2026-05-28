<?php

declare(strict_types=1);

namespace App\Tests\Core\Config;

use App\Core\Config\Settings\CoreSettingDefinition;
use App\Core\Config\Settings\CoreSettingsRegistry;
use App\Core\Log\ConfigAuditLogPolicy;
use App\Core\Statistics\AccessStatisticsPolicy;
use App\Form\FormInputType;
use App\Localization\TranslationLanguageCatalog;
use App\Security\UserFlowConfig;
use PHPUnit\Framework\TestCase;

final class CoreSettingsRegistryTest extends TestCase
{
    public function testItDefinesKnownCoreSettingsForAdminForms(): void
    {
        $registry = new CoreSettingsRegistry(new TranslationLanguageCatalog(dirname(__DIR__, 3)));

        $general = $registry->definitions('general');
        $users = $registry->definitions('users');
        $security = $registry->definitions('security');
        $statistics = $registry->definitions('statistics');

        self::assertSame([
            'site.title',
            'site.url',
            'localization.default_language',
            'localization.route_prefixes_enabled',
            'content.home_path',
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
            'user.menu.enabled',
            'user.menu.sort_order',
        ], array_map(static fn (CoreSettingDefinition $definition): string => $definition->key(), $users));

        self::assertSame([
            'security.captcha.enabled',
            'security.captcha.provider',
            'security.captcha.preview',
            ConfigAuditLogPolicy::ENABLED_KEY,
            ConfigAuditLogPolicy::EVENTS_KEY,
        ], array_map(static fn (CoreSettingDefinition $definition): string => $definition->key(), $security));
        self::assertSame(FormInputType::Captcha, $security[2]->formField()->inputType());
        self::assertSame(FormInputType::MultiSelect, $security[4]->formField()->inputType());
        self::assertSame(ConfigAuditLogPolicy::DEFAULT_CATEGORIES, $security[4]->defaultValue());

        self::assertSame([
            AccessStatisticsPolicy::ENABLED_KEY,
            AccessStatisticsPolicy::RESPECT_DO_NOT_TRACK_KEY,
        ], array_map(static fn (CoreSettingDefinition $definition): string => $definition->key(), $statistics));
        self::assertTrue($statistics[0]->defaultValue());
        self::assertTrue($statistics[1]->defaultValue());
    }

    public function testItKeepsContentEditorSectionsOutOfTheAdminSettingsRegistry(): void
    {
        $registry = new CoreSettingsRegistry(new TranslationLanguageCatalog(dirname(__DIR__, 3)));

        self::assertSame([], $registry->definitions('content'));
        self::assertSame([], $registry->definitions('schemas'));
        self::assertSame([], $registry->definitions('imports'));
    }
}
