<?php

declare(strict_types=1);

namespace App\Tests\Core\Config;

use App\Core\Access\AccessActor;
use App\Core\Access\AccessLevel;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Config\Settings\CoreSettingsFormHandler;
use App\Core\Config\Settings\CoreSettingsRegistry;
use App\Core\Geo\MaxMindGeoIpConfig;
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
        $config->set(MaxMindGeoIpConfig::LICENSE_KEY_KEY, 'secret-license-key', ConfigValueType::String, sensitive: true);

        $handler = new CoreSettingsFormHandler(
            $this->registry(),
            $config,
            new FormSubmissionHandler(),
            $this->createStub(EntityManagerInterface::class),
        );

        $result = $handler->submit('statistics', [
            'statistics.enabled' => '1',
            'statistics.respect_do_not_track' => '1',
            MaxMindGeoIpConfig::ENABLED_KEY => '0',
            MaxMindGeoIpConfig::DATABASE_PATH_KEY => MaxMindGeoIpConfig::DEFAULT_DATABASE_PATH,
            MaxMindGeoIpConfig::LICENSE_KEY_KEY => '',
        ], 'test', AccessActor::fromAccess(AccessLevel::OWNER));

        self::assertTrue($result->isValid());
        self::assertSame('secret-license-key', $config->get(MaxMindGeoIpConfig::LICENSE_KEY_KEY));
    }

    public function testItPreservesExistingSensitiveSettingsWhenSubmittedProtectedPlaceholder(): void
    {
        $config = new Config($this->connection());
        $config->set(MaxMindGeoIpConfig::LICENSE_KEY_KEY, 'secret-license-key', ConfigValueType::String, sensitive: true);

        $handler = new CoreSettingsFormHandler(
            $this->registry(),
            $config,
            new FormSubmissionHandler(),
            $this->createStub(EntityManagerInterface::class),
        );

        $result = $handler->submit('statistics', [
            'statistics.enabled' => '1',
            'statistics.respect_do_not_track' => '1',
            MaxMindGeoIpConfig::ENABLED_KEY => '1',
            MaxMindGeoIpConfig::DATABASE_PATH_KEY => MaxMindGeoIpConfig::DEFAULT_DATABASE_PATH,
            MaxMindGeoIpConfig::LICENSE_KEY_KEY => '[protected]',
        ], 'test', AccessActor::fromAccess(AccessLevel::OWNER));

        self::assertTrue($result->isValid());
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
