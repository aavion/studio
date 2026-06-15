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
        self::assertSame(MaxMindGeoIpConfig::PROVIDER_KEY, $config->provider());
        self::assertSame(MaxMindGeoIpConfig::DEFAULT_DATABASE_PATH, $config->databasePath());
        self::assertSame(['en'], $config->locales());
        self::assertFalse($config->updateEnabled());
        self::assertSame('weekly', $config->updateInterval());
    }

    public function testItNormalizesLocalesAndUpdateInterval(): void
    {
        $connection = $this->connection();
        $store = new Config($connection);
        $store->set(MaxMindGeoIpConfig::ENABLED_KEY, true, ConfigValueType::Boolean);
        $store->set(MaxMindGeoIpConfig::SELECTED_PROVIDER_KEY, MaxMindGeoIpConfig::PROVIDER_KEY, ConfigValueType::String);
        $store->set(MaxMindGeoIpConfig::LOCALES_KEY, ['de', 'invalid', 'en', 'de', 'fr-FR'], ConfigValueType::Json);
        $store->set(MaxMindGeoIpConfig::UPDATE_INTERVAL_KEY, 'hourly', ConfigValueType::String);

        $config = new MaxMindGeoIpConfig($store);

        self::assertTrue($config->enabled());
        self::assertSame(['de', 'en', 'fr-FR'], $config->locales());
        self::assertSame('weekly', $config->updateInterval());
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');

        return $connection;
    }
}
