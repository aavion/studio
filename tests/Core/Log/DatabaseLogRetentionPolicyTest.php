<?php

declare(strict_types=1);

namespace App\Tests\Core\Log;

use App\Core\Log\DatabaseLogRetentionPolicy;
use App\Security\AutoBan\AutoBanPolicy;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class DatabaseLogRetentionPolicyTest extends TestCase
{
    public function testSecuritySignalRetentionIsFlooredToMaximumAutoBanTtl(): void
    {
        $connection = $this->connection();
        $this->insertConfig($connection, DatabaseLogRetentionPolicy::SECURITY_SIGNAL_RETENTION_DAYS_KEY, 1);

        self::assertSame(AutoBanPolicy::maxTtlDays(), (new DatabaseLogRetentionPolicy($connection))->retentionDaysForSignal());
    }

    public function testSecuritySignalRetentionIsCappedAtMaximumRetention(): void
    {
        $connection = $this->connection();
        $this->insertConfig($connection, DatabaseLogRetentionPolicy::SECURITY_SIGNAL_RETENTION_DAYS_KEY, 365);

        self::assertSame(DatabaseLogRetentionPolicy::MAX_RETENTION_DAYS, (new DatabaseLogRetentionPolicy($connection))->retentionDaysForSignal());
    }

    public function testLogRetentionSourcesKeepOneDayMinimum(): void
    {
        $connection = $this->connection();
        $this->insertConfig($connection, DatabaseLogRetentionPolicy::MESSAGE_LOG_RETENTION_DAYS_KEY, 1);

        self::assertSame(1, (new DatabaseLogRetentionPolicy($connection))->retentionDaysForSource('message'));
    }

    public function testDefaultSecuritySignalRetentionIsValidForAutoBanTtl(): void
    {
        self::assertGreaterThanOrEqual(AutoBanPolicy::maxTtlDays(), DatabaseLogRetentionPolicy::defaultSecuritySignalRetentionDays());
        self::assertLessThanOrEqual(DatabaseLogRetentionPolicy::MAX_RETENTION_DAYS, DatabaseLogRetentionPolicy::defaultSecuritySignalRetentionDays());
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) PRIMARY KEY NOT NULL, value CLOB NOT NULL, value_type VARCHAR(255) NOT NULL, sensitive BOOLEAN NOT NULL, modified_at DATETIME NOT NULL, modified_by VARCHAR(180) DEFAULT NULL)');

        return $connection;
    }

    private function insertConfig(Connection $connection, string $key, int $value): void
    {
        $connection->insert('config_entry', [
            'config_key' => $key,
            'value' => json_encode($value, JSON_THROW_ON_ERROR),
            'value_type' => 'integer',
            'sensitive' => 0,
            'modified_at' => '2026-06-18 12:00:00',
            'modified_by' => 'test',
        ]);
    }
}
