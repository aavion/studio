<?php

declare(strict_types=1);

namespace App\Tests\Core\Config;

use App\Core\Config\Api\SettingsApiReadModel;
use App\Core\Access\AccessActor;
use App\Core\Access\AccessLevel;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Config\Settings\CoreSettingsRegistry;
use App\Core\Geo\MaxMindGeoIpConfig;
use App\Localization\TranslationLanguageCatalog;
use App\View\SystemExtensionMetadataProvider;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class SettingsApiReadModelTest extends TestCase
{
    public function testItRedactsSensitiveSettingsForApiDisplayButKeepsFormValuesEmpty(): void
    {
        $config = new Config($this->connection());
        $config->set(MaxMindGeoIpConfig::LICENSE_KEY_KEY, 'secret-license-key', ConfigValueType::String, sensitive: true);

        $readModel = new SettingsApiReadModel($this->registry(), $config);

        $licenseSetting = null;
        foreach ($readModel->settings('statistics', AccessActor::fromAccess(AccessLevel::OWNER)) as $setting) {
            if (MaxMindGeoIpConfig::LICENSE_KEY_KEY === $setting['id']) {
                $licenseSetting = $setting;
            }
        }

        self::assertIsArray($licenseSetting);
        self::assertSame('[protected]', $licenseSetting['attributes']['value']);
        self::assertSame('', $readModel->values('statistics', AccessActor::fromAccess(AccessLevel::OWNER))[MaxMindGeoIpConfig::LICENSE_KEY_KEY]);
        self::assertArrayNotHasKey(MaxMindGeoIpConfig::LICENSE_KEY_KEY, $readModel->values('statistics', AccessActor::fromAccess(AccessLevel::ADMIN)));
    }

    private function registry(): CoreSettingsRegistry
    {
        $projectDir = dirname(__DIR__, 3);

        return new CoreSettingsRegistry(new TranslationLanguageCatalog($projectDir), new SystemExtensionMetadataProvider($projectDir));
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');

        return $connection;
    }
}
