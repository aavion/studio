<?php

declare(strict_types=1);

namespace App\Tests\Core\Geo;

use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Geo\MaxMindGeoIpConfig;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class MaxMindGeoIpConfigTest extends TestCase
{
    public function testItUsesSafeDefaults(): void
    {
        $config = new MaxMindGeoIpConfig(new Config($this->connection()));

        self::assertFalse($config->enabled());
        self::assertSame(MaxMindGeoIpConfig::DEFAULT_DATABASE_PATH, $config->databasePath());
        self::assertSame(['en'], $config->locales());
        self::assertSame('', $config->licenseKey());
        self::assertFalse($config->hasLicenseKey());
    }

    public function testItUsesConfiguredDefaultLanguageForLocalesAndNormalizesSensitiveSettings(): void
    {
        $connection = $this->connection();
        $store = new Config($connection);
        $store->set(MaxMindGeoIpConfig::ENABLED_KEY, true, ConfigValueType::Boolean);
        $store->set('localization.default_language', 'de', ConfigValueType::String);
        $store->set(MaxMindGeoIpConfig::LICENSE_KEY_KEY, ' test-license ', ConfigValueType::String, sensitive: true);

        $config = new MaxMindGeoIpConfig($store);

        self::assertTrue($config->enabled());
        self::assertSame(['de', 'en'], $config->locales());
        self::assertSame('test-license', $config->licenseKey());
        self::assertTrue($config->hasLicenseKey());
        self::assertStringContainsString('license_key=test-license', $config->downloadUrl());
    }

    public function testItResolvesSafeProjectRelativeDatabasePath(): void
    {
        $store = new Config($this->connection());
        $config = new MaxMindGeoIpConfig($store);

        self::assertSame('/project/var/geoip2/GeoLite2-City.mmdb', $config->databaseAbsolutePath('/project'));

        $store->set(MaxMindGeoIpConfig::DATABASE_PATH_KEY, '../secret.mmdb', ConfigValueType::String);

        self::assertNull((new MaxMindGeoIpConfig($store))->databaseAbsolutePath('/project'));
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');

        return $connection;
    }
}
