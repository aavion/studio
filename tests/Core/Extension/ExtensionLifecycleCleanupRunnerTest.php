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
                'action' => 'delete_extension_settings',
                'count' => 1,
            ],
            [
                'action' => 'delete_extension_acl_override',
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
}
