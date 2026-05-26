<?php

declare(strict_types=1);

namespace App\Tests\Core\Package;

use App\Core\Config\ConfigValueType;
use App\Core\Package\Settings\PackageSettings;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PackageSettingsTest extends KernelTestCase
{
    private const PACKAGE = 'test-settings-package';

    protected function tearDown(): void
    {
        if (self::$booted) {
            self::getContainer()->get(Connection::class)->delete('package_setting_entry', ['package_name' => self::PACKAGE]);
        }

        parent::tearDown();
    }

    public function testItStoresReadsAndDeletesPackageScopedSettings(): void
    {
        self::bootKernel();
        $settings = self::getContainer()->get(PackageSettings::class);

        self::assertSame('blue', $settings->get(self::PACKAGE, 'theme.variant', 'blue'));
        self::assertTrue($settings->set(self::PACKAGE, 'theme.variant', 'green', ConfigValueType::String));
        self::assertSame('green', $settings->get(self::PACKAGE, 'theme.variant', 'blue'));
        self::assertSame(1, $settings->removePackage(self::PACKAGE));
        self::assertSame('blue', $settings->get(self::PACKAGE, 'theme.variant', 'blue'));
    }

    public function testItReturnsDefaultsForInvalidKeys(): void
    {
        self::bootKernel();
        $settings = self::getContainer()->get(PackageSettings::class);

        self::assertFalse($settings->set(self::PACKAGE, 'Invalid Key', true));
        self::assertSame('fallback', $settings->get(self::PACKAGE, 'Invalid Key', 'fallback'));
    }
}
