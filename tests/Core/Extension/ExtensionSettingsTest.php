<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Config\ConfigValueType;
use App\Core\Extension\Settings\ExtensionSettings;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExtensionSettingsTest extends KernelTestCase
{
    private const EXTENSION = 'test-settings-extension';

    protected function tearDown(): void
    {
        if (self::$booted) {
            self::getContainer()->get(Connection::class)->delete('extension_setting_entry', ['extension_name' => self::EXTENSION]);
        }

        parent::tearDown();
    }

    public function testItStoresReadsAndDeletesExtensionScopedSettings(): void
    {
        self::bootKernel();
        $settings = self::getContainer()->get(ExtensionSettings::class);

        self::assertSame('blue', $settings->get(self::EXTENSION, 'theme.variant', 'blue'));
        self::assertTrue($settings->set(self::EXTENSION, 'theme.variant', 'green', ConfigValueType::String));
        self::assertSame('green', $settings->get(self::EXTENSION, 'theme.variant', 'blue'));
        self::assertSame(1, $settings->removeExtension(self::EXTENSION));
        self::assertSame('blue', $settings->get(self::EXTENSION, 'theme.variant', 'blue'));
    }

    public function testItReturnsDefaultsForInvalidKeys(): void
    {
        self::bootKernel();
        $settings = self::getContainer()->get(ExtensionSettings::class);

        self::assertFalse($settings->set(self::EXTENSION, 'Invalid Key', true));
        self::assertSame('fallback', $settings->get(self::EXTENSION, 'Invalid Key', 'fallback'));
    }
}
