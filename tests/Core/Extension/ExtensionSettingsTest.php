<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Config\ConfigValueType;
use App\Core\Extension\ActiveExtensionProviderInterface;
use App\Core\Extension\ExtensionManifestVariables;
use App\Core\Extension\ExtensionScope;
use App\Core\Extension\ExtensionStatus;
use App\Core\Extension\Settings\ExtensionSettings;
use App\Core\Manifest\Manifest;
use App\Entity\Extension;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
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

    public function testItReadsManifestDefaultsThroughExtensionSettingsAndAllowsPersistedOverrides(): void
    {
        $variables = (new ExtensionManifestVariables())->fromManifest('icon-captcha', new Manifest([
            'EXTENSION_SLUG' => 'icon-captcha',
            'EXTENSION_SOMEKEY' => 'hallo welt',
        ]));
        $extension = new Extension(
            '10000000-0000-7000-8000-000000000903',
            [ExtensionScope::Module],
            'icon-captcha',
            'extensions/icon-captcha',
            ExtensionStatus::Active,
            ['variables' => $variables],
        );
        $settings = new ExtensionSettings(
            $this->connection(),
            activeExtensionProvider: new StaticExtensionSettingsActiveExtensionProvider([$extension]),
        );

        self::assertSame('hallo welt', $settings->get('icon-captcha', 'manifest.somekey', 'fallback'));
        self::assertTrue($settings->set('icon-captcha', 'manifest.somekey', 'persisted value', ConfigValueType::String));
        self::assertSame('persisted value', $settings->get('icon-captcha', 'manifest.somekey', 'fallback'));
        self::assertSame('hallo welt', $extension->manifestVariables()['ext.icon_captcha.somekey']['value']);
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE extension_setting_entry (extension_name VARCHAR(120) NOT NULL, setting_key VARCHAR(160) NOT NULL, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, metadata CLOB NOT NULL, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL, PRIMARY KEY (extension_name, setting_key))');

        return $connection;
    }
}

final readonly class StaticExtensionSettingsActiveExtensionProvider implements ActiveExtensionProviderInterface
{
    /**
     * @param list<Extension> $extensions
     */
    public function __construct(private array $extensions)
    {
    }

    public function extensions(?ExtensionScope $scope = null): array
    {
        if (null === $scope) {
            return $this->extensions;
        }

        return array_values(array_filter(
            $this->extensions,
            static fn (Extension $extension): bool => $extension->hasScope($scope),
        ));
    }

    public function extension(string $extensionName): ?Extension
    {
        foreach ($this->extensions as $extension) {
            if ($extension->extensionName() === $extensionName) {
                return $extension;
            }
        }

        return null;
    }
}
