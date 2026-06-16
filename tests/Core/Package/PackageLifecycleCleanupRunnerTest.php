<?php

declare(strict_types=1);

namespace App\Tests\Core\Package;

use App\Core\AdminAcl\AdminFeatureOverrideStore;
use App\Core\AdminAcl\AdminFeatureRegistry;
use App\Core\AdminAcl\AdminPermissionState;
use App\Core\Config\ConfigValueType;
use App\Core\Package\ExtensionPackageStatus;
use App\Core\Package\PackageLifecycleCleanupRunner;
use App\Core\Package\PackageScope;
use App\Core\Package\Settings\PackageSettings;
use App\Entity\ExtensionPackage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PackageLifecycleCleanupRunnerTest extends KernelTestCase
{
    public function testItDeletesPackageSettingsDuringCleanup(): void
    {
        self::bootKernel();
        $settings = self::getContainer()->get(PackageSettings::class);
        $overrides = self::getContainer()->get(AdminFeatureOverrideStore::class);
        $registry = self::getContainer()->get(AdminFeatureRegistry::class);
        self::assertInstanceOf(AdminFeatureRegistry::class, $registry);
        $runner = new PackageLifecycleCleanupRunner($settings, $overrides, $registry);

        $settings->set('cleanup-module', 'display.mode', 'compact', ConfigValueType::String);
        $settings->set('neighbor-module', 'display.mode', 'comfortable', ConfigValueType::String);
        $overrides->save([
            'admin.settings.packages.cleanup-module' => [
                'state' => AdminPermissionState::Mutable->value,
                'groups' => [],
            ],
            'admin.settings.packages.neighbor-module' => [
                'state' => AdminPermissionState::Visible->value,
                'groups' => [],
            ],
        ], 'test');

        $result = $runner->cleanup(new ExtensionPackage(
            '10000000-0000-7000-8000-000000000611',
            [PackageScope::Module],
            'cleanup-module',
            'packages/cleanup-module',
            ExtensionPackageStatus::Removed,
        ));

        self::assertTrue($result->isSuccess());
        self::assertSame([
            [
                'action' => 'delete_package_settings',
                'count' => 1,
            ],
            [
                'action' => 'delete_package_acl_override',
                'count' => 1,
            ],
        ], $result->value()['actions']);
        self::assertSame('fallback', $settings->get('cleanup-module', 'display.mode', 'fallback'));
        self::assertSame('comfortable', $settings->get('neighbor-module', 'display.mode', 'fallback'));
        self::assertArrayNotHasKey('admin.settings.packages.cleanup-module', $overrides->overrides());
        self::assertArrayHasKey('admin.settings.packages.neighbor-module', $overrides->overrides());

        $settings->removePackage('neighbor-module');
        $overrides->save([], 'test');
    }
}
