<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\AdminAcl\AdminFeatureOverrideStore;
use App\Core\AdminAcl\AdminFeatureRegistry;
use App\Core\AdminAcl\AdminPermissionState;
use App\Core\Config\ConfigValueType;
use App\Core\Extension\ExtensionStatus;
use App\Core\Extension\ExtensionLifecycleCleanupRunner;
use App\Core\Extension\ExtensionScope;
use App\Core\Extension\Settings\ExtensionSettings;
use App\Entity\Extension;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExtensionLifecycleCleanupRunnerTest extends KernelTestCase
{
    public function testItDeletesExtensionSettingsDuringCleanup(): void
    {
        self::bootKernel();
        $settings = self::getContainer()->get(ExtensionSettings::class);
        $overrides = self::getContainer()->get(AdminFeatureOverrideStore::class);
        $registry = self::getContainer()->get(AdminFeatureRegistry::class);
        self::assertInstanceOf(AdminFeatureRegistry::class, $registry);
        $runner = new ExtensionLifecycleCleanupRunner($settings, $overrides, $registry);

        $settings->set('cleanup-module', 'display.mode', 'compact', ConfigValueType::String);
        $settings->set('neighbor-module', 'display.mode', 'comfortable', ConfigValueType::String);
        $overrides->save([
            'admin.settings.extensions.cleanup-module' => [
                'state' => AdminPermissionState::Mutable->value,
                'groups' => [],
            ],
            'admin.settings.extensions.neighbor-module' => [
                'state' => AdminPermissionState::Visible->value,
                'groups' => [],
            ],
        ], 'test');

        $result = $runner->cleanup(new Extension(
            '10000000-0000-7000-8000-000000000611',
            [ExtensionScope::Module],
            'cleanup-module',
            'extensions/cleanup-module',
            ExtensionStatus::Removed,
        ));

        self::assertTrue($result->isSuccess());
        self::assertSame([
            [
                'action' => 'delete_extension_acl_override',
                'count' => 1,
            ],
            [
                'action' => 'delete_extension_settings',
                'count' => 1,
            ],
        ], $result->value()['actions']);
        self::assertSame('fallback', $settings->get('cleanup-module', 'display.mode', 'fallback'));
        self::assertSame('comfortable', $settings->get('neighbor-module', 'display.mode', 'fallback'));
        self::assertArrayNotHasKey('admin.settings.extensions.cleanup-module', $overrides->overrides());
        self::assertArrayHasKey('admin.settings.extensions.neighbor-module', $overrides->overrides());

        $settings->removeExtension('neighbor-module');
        $overrides->save([], 'test');
    }

    public function testItFailsCleanupWhenExtensionAclOverrideCannotBeRemoved(): void
    {
        self::bootKernel();
        $settings = self::getContainer()->get(ExtensionSettings::class);
        $overrides = self::getContainer()->get(AdminFeatureOverrideStore::class);
        $registry = self::getContainer()->get(AdminFeatureRegistry::class);
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $runner = new ExtensionLifecycleCleanupRunner($settings, $overrides, $registry);

        $settings->set('cleanup-module', 'display.mode', 'compact', ConfigValueType::String);
        $overrides->save([
            'admin.settings.extensions.cleanup-module' => [
                'state' => AdminPermissionState::Mutable->value,
                'groups' => [],
            ],
        ], 'test');
        $connection->executeStatement(<<<'SQL'
            CREATE TRIGGER fail_extension_acl_cleanup
            BEFORE UPDATE ON config_entry
            WHEN NEW.config_key = 'acl.admin.features'
            BEGIN
                SELECT RAISE(FAIL, 'acl cleanup blocked');
            END
            SQL);

        try {
            $result = $runner->cleanup(new Extension(
                '10000000-0000-7000-8000-000000000612',
                [ExtensionScope::Module],
                'cleanup-module',
                'extensions/cleanup-module',
                ExtensionStatus::Removed,
            ));
        } finally {
            $connection->executeStatement('DROP TRIGGER IF EXISTS fail_extension_acl_cleanup');
        }

        self::assertFalse($result->isSuccess());
        self::assertSame('config.write_failed', $result->firstIssue()?->code());
        self::assertSame('acl.admin.features', $result->firstIssue()?->context()['config_key'] ?? null);
        self::assertSame('compact', $settings->get('cleanup-module', 'display.mode', 'fallback'));
        self::assertArrayHasKey('admin.settings.extensions.cleanup-module', $overrides->overrides());

        $settings->removeExtension('cleanup-module');
        $overrides->save([], 'test');
    }

    public function testItFailsCleanupWhenExtensionSettingsCannotBeRemoved(): void
    {
        self::bootKernel();
        $settings = self::getContainer()->get(ExtensionSettings::class);
        $overrides = self::getContainer()->get(AdminFeatureOverrideStore::class);
        $registry = self::getContainer()->get(AdminFeatureRegistry::class);
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $runner = new ExtensionLifecycleCleanupRunner($settings, $overrides, $registry);

        $settings->set('cleanup-module', 'display.mode', 'compact', ConfigValueType::String);
        $connection->executeStatement(<<<'SQL'
            CREATE TRIGGER fail_extension_settings_cleanup
            BEFORE DELETE ON extension_setting_entry
            WHEN OLD.extension_name = 'cleanup-module'
            BEGIN
                SELECT RAISE(FAIL, 'settings cleanup blocked');
            END
            SQL);

        try {
            $result = $runner->cleanup(new Extension(
                '10000000-0000-7000-8000-000000000613',
                [ExtensionScope::Module],
                'cleanup-module',
                'extensions/cleanup-module',
                ExtensionStatus::Removed,
            ));
        } finally {
            $connection->executeStatement('DROP TRIGGER IF EXISTS fail_extension_settings_cleanup');
        }

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.setting.delete_failed', $result->firstIssue()?->code());
        self::assertSame('compact', $settings->get('cleanup-module', 'display.mode', 'fallback'));

        $settings->removeExtension('cleanup-module');
        $overrides->save([], 'test');
    }
}
