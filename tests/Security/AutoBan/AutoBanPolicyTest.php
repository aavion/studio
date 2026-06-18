<?php

declare(strict_types=1);

namespace App\Tests\Security\AutoBan;

use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Config\Settings\CoreConfigDefaultProvider;
use App\Core\Config\Settings\CoreSettingsRegistry;
use App\Database\DatabaseReadyState;
use App\Localization\TranslationLanguageCatalog;
use App\Security\AutoBan\AutoBanPolicy;
use App\Setup\SetupCompletionMarker;
use App\View\SystemPackageMetadataProvider;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class AutoBanPolicyTest extends TestCase
{
    public function testItDisablesAutoBanWhenConfigStorageIsUnavailable(): void
    {
        $projectDir = dirname(__DIR__, 3);
        $policy = new AutoBanPolicy(new Config(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            databaseReadyState: new DatabaseReadyState(new SetupCompletionMarker(), sys_get_temp_dir().'/missing-auto-ban-config', 'test'),
            defaultProvider: new CoreConfigDefaultProvider(new CoreSettingsRegistry(
                new TranslationLanguageCatalog($projectDir),
                new SystemPackageMetadataProvider($projectDir),
            )),
        ));

        self::assertFalse($policy->enabled());
    }

    public function testItUsesPersistedSetupEnabledValueWhenConfigStorageIsAvailable(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');
        $config = new Config($connection);
        $config->set(AutoBanPolicy::ENABLED_KEY, AutoBanPolicy::SETUP_ENABLED, ConfigValueType::Boolean);

        self::assertTrue((new AutoBanPolicy($config))->enabled());
    }
}
