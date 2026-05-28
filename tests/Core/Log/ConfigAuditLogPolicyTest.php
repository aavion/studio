<?php

declare(strict_types=1);

namespace App\Tests\Core\Log;

use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Log\ConfigAuditLogPolicy;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class ConfigAuditLogPolicyTest extends TestCase
{
    public function testItAllowsProductionDefaultAuditCategories(): void
    {
        $policy = new ConfigAuditLogPolicy(new Config($this->connection()));

        self::assertTrue($policy->allows('auth.login_success'));
        self::assertTrue($policy->allows('backend.action.package_discovery'));
        self::assertTrue($policy->allows('operations.cleanup'));
        self::assertTrue($policy->allows('package.lifecycle.activate'));
        self::assertTrue($policy->allows('settings.core.save'));
        self::assertTrue($policy->allows('future.audit_event'));
    }

    public function testItRespectsDisabledAuditLogging(): void
    {
        $connection = $this->connection();
        $config = new Config($connection);
        $config->set(ConfigAuditLogPolicy::ENABLED_KEY, false, ConfigValueType::Boolean);

        $policy = new ConfigAuditLogPolicy($config);

        self::assertFalse($policy->allows('auth.login_success'));
    }

    public function testItFiltersConfiguredCategories(): void
    {
        $connection = $this->connection();
        $config = new Config($connection);
        $config->set(ConfigAuditLogPolicy::EVENTS_KEY, [ConfigAuditLogPolicy::CATEGORY_SETTINGS], ConfigValueType::Json);

        $policy = new ConfigAuditLogPolicy($config);

        self::assertTrue($policy->allows('settings.core.save'));
        self::assertFalse($policy->allows('package.lifecycle.activate'));
    }

    public function testItPreservesEmptyAuditCategorySelections(): void
    {
        $connection = $this->connection();
        $config = new Config($connection);
        $config->set(ConfigAuditLogPolicy::EVENTS_KEY, [], ConfigValueType::Json);

        $policy = new ConfigAuditLogPolicy($config);

        self::assertFalse($policy->allows('auth.login_success'));
        self::assertFalse($policy->allows('settings.core.save'));
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');

        return $connection;
    }
}
