<?php

declare(strict_types=1);

namespace App\Tests\Core\Config;

use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Config\Settings\CoreSettingsFormHandler;
use App\Core\Config\Settings\CoreSettingsRegistry;
use App\Core\Geo\MaxMindGeoIpConfig;
use App\Core\Log\ConfigAuditLogPolicy;
use App\Form\FormSubmissionHandler;
use App\Localization\TranslationLanguageCatalog;
use App\View\SystemPackageMetadataProvider;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class CoreSettingsFormHandlerTest extends TestCase
{
    public function testItPreservesExistingSensitiveSettingsWhenSubmittedEmpty(): void
    {
        $config = new Config($this->connection());
        $config->set(MaxMindGeoIpConfig::ACCOUNT_ID_KEY, '123456', ConfigValueType::String, sensitive: true);
        $config->set(MaxMindGeoIpConfig::LICENSE_KEY_KEY, 'secret-license-key', ConfigValueType::String, sensitive: true);

        $handler = new CoreSettingsFormHandler(
            $this->registry(),
            $config,
            new FormSubmissionHandler(),
            $this->createStub(EntityManagerInterface::class),
        );

        $result = $handler->submit('security', [
            'security.captcha.enabled' => '0',
            'security.captcha.provider' => 'none',
            ConfigAuditLogPolicy::ENABLED_KEY => '1',
            ConfigAuditLogPolicy::EVENTS_KEY => ConfigAuditLogPolicy::DEFAULT_CATEGORIES,
            MaxMindGeoIpConfig::ENABLED_KEY => '0',
            MaxMindGeoIpConfig::SELECTED_PROVIDER_KEY => MaxMindGeoIpConfig::PROVIDER_KEY,
            MaxMindGeoIpConfig::DATABASE_PATH_KEY => MaxMindGeoIpConfig::DEFAULT_DATABASE_PATH,
            MaxMindGeoIpConfig::LOCALES_KEY => '["en"]',
            MaxMindGeoIpConfig::UPDATE_ENABLED_KEY => '0',
            MaxMindGeoIpConfig::UPDATE_INTERVAL_KEY => MaxMindGeoIpConfig::DEFAULT_UPDATE_INTERVAL,
            MaxMindGeoIpConfig::ACCOUNT_ID_KEY => '',
            MaxMindGeoIpConfig::LICENSE_KEY_KEY => '',
        ], 'test');

        self::assertTrue($result->isValid());
        self::assertSame('123456', $config->get(MaxMindGeoIpConfig::ACCOUNT_ID_KEY));
        self::assertSame('secret-license-key', $config->get(MaxMindGeoIpConfig::LICENSE_KEY_KEY));
    }

    private function registry(): CoreSettingsRegistry
    {
        $projectDir = dirname(__DIR__, 3);

        return new CoreSettingsRegistry(new TranslationLanguageCatalog($projectDir), new SystemPackageMetadataProvider($projectDir));
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');

        return $connection;
    }
}
