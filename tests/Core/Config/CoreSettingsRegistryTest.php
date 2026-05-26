<?php

declare(strict_types=1);

namespace App\Tests\Core\Config;

use App\Core\Config\Settings\CoreSettingDefinition;
use App\Core\Config\Settings\CoreSettingsRegistry;
use App\Form\FormInputType;
use App\Localization\TranslationLanguageCatalog;
use PHPUnit\Framework\TestCase;

final class CoreSettingsRegistryTest extends TestCase
{
    public function testItDefinesKnownCoreSettingsForAdminForms(): void
    {
        $registry = new CoreSettingsRegistry(new TranslationLanguageCatalog(dirname(__DIR__, 3)));

        $general = $registry->definitions('general');
        $users = $registry->definitions('users');
        $security = $registry->definitions('security');

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
            'user.registration.enabled',
            'user.default_acl_group',
            'user.menu.enabled',
            'user.menu.sort_order',
        ], array_map(static fn (CoreSettingDefinition $definition): string => $definition->key(), $users));

        self::assertSame('security.captcha.preview', $security[2]->key());
        self::assertSame(FormInputType::Captcha, $security[2]->formField()->inputType());
    }

    public function testItKeepsContentEditorSectionsOutOfTheAdminSettingsRegistry(): void
    {
        $registry = new CoreSettingsRegistry(new TranslationLanguageCatalog(dirname(__DIR__, 3)));

        self::assertSame([], $registry->definitions('content'));
        self::assertSame([], $registry->definitions('schemas'));
        self::assertSame([], $registry->definitions('imports'));
    }
}
