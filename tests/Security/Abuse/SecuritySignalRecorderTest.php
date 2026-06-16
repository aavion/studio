<?php

declare(strict_types=1);

namespace App\Tests\Security\Abuse;

use App\Core\Log\DatabaseLogRetentionPolicy;
use App\Database\DatabaseReadyState;
use App\Security\Abuse\SecuritySignalRecorder;
use App\Setup\SetupCompletionMarker;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class SecuritySignalRecorderTest extends TestCase
{
    public function testItDoesNotTouchDatabaseWhenSetupIsNotComplete(): void
    {
        $setupState = $this->setEnvironment(SetupCompletionMarker::KEY, '0');
        $allowState = $this->setEnvironment(DatabaseReadyState::ALLOW_UNREADY_KEY, '0');
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('insert');
        $connection->expects(self::never())->method('fetchOne');
        $connection->expects(self::never())->method('executeStatement');

        try {
            $recorder = new SecuritySignalRecorder(
                $connection,
                new DatabaseLogRetentionPolicy($connection),
                new DatabaseReadyState(new SetupCompletionMarker(), sys_get_temp_dir().'/missing-system-project', 'test'),
            );

            $recorder->record('probe', 'security.probe.setup', 'visitor', 'visitor-id');
        } finally {
            $this->restoreEnvironment(SetupCompletionMarker::KEY, $setupState);
            $this->restoreEnvironment(DatabaseReadyState::ALLOW_UNREADY_KEY, $allowState);
        }
    }

    public function testItRecordsSignalsWithShortIpDerivedRetentionAndPurgesExpiredRows(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) PRIMARY KEY NOT NULL, value CLOB NOT NULL, value_type VARCHAR(255) NOT NULL, sensitive BOOLEAN NOT NULL, modified_at DATETIME NOT NULL, modified_by VARCHAR(180) DEFAULT NULL)');
        $connection->executeStatement('CREATE TABLE security_signal_event (uid VARCHAR(36) PRIMARY KEY NOT NULL, occurred_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, signal_type VARCHAR(80) NOT NULL, reason_code VARCHAR(120) NOT NULL, severity VARCHAR(16) NOT NULL, confidence INTEGER NOT NULL, subject_type VARCHAR(40) NOT NULL, subject_identifier VARCHAR(190) NOT NULL, ip_derived BOOLEAN NOT NULL, request_family VARCHAR(40) NOT NULL, request_intent VARCHAR(80) NOT NULL, request_id VARCHAR(64) NOT NULL, visitor_id VARCHAR(64) NOT NULL, path VARCHAR(1024) NOT NULL, route VARCHAR(190) NOT NULL, http_status INTEGER DEFAULT NULL, context CLOB NOT NULL)');
        $connection->insert('config_entry', [
            'config_key' => DatabaseLogRetentionPolicy::SECURITY_SIGNAL_IP_RETENTION_DAYS_KEY,
            'value' => '1',
            'value_type' => 'integer',
            'sensitive' => 0,
            'modified_at' => '2026-06-16 00:00:00',
            'modified_by' => 'test',
        ]);
        $connection->insert('security_signal_event', [
            'uid' => '99999999-0000-7000-8000-000000000001',
            'occurred_at' => '2000-01-01 00:00:00',
            'expires_at' => '2000-01-02 00:00:00',
            'signal_type' => 'probe',
            'reason_code' => 'security.probe.old',
            'severity' => 'WARNING',
            'confidence' => 90,
            'subject_type' => 'ip_hash',
            'subject_identifier' => 'old',
            'ip_derived' => 1,
            'request_family' => 'browser',
            'request_intent' => 'suspicious_probe',
            'request_id' => 'old',
            'visitor_id' => 'n/a',
            'path' => '/.env',
            'route' => 'n/a',
            'http_status' => 400,
            'context' => '{}',
        ]);

        $recorder = new SecuritySignalRecorder($connection, new DatabaseLogRetentionPolicy($connection));
        $recorder->record(
            'probe',
            'security.probe.env',
            'ip_hash',
            'hash-value',
            ipDerived: true,
            severity: 'warning',
            confidence: 140,
            requestFamily: 'browser',
            requestIntent: 'suspicious_probe',
            path: '/.env',
            httpStatus: 400,
        );

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM security_signal_event'));
        self::assertSame('security.probe.env', $connection->fetchOne('SELECT reason_code FROM security_signal_event'));
        self::assertSame(100, (int) $connection->fetchOne('SELECT confidence FROM security_signal_event'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT ip_derived FROM security_signal_event'));
        self::assertGreaterThan(new \DateTimeImmutable(), new \DateTimeImmutable((string) $connection->fetchOne('SELECT expires_at FROM security_signal_event')));
    }

    /**
     * @return array{server_exists: bool, server_value: mixed, env_exists: bool, env_value: mixed, getenv_value: string|false}
     */
    private function setEnvironment(string $key, string $value): array
    {
        $state = [
            'server_exists' => array_key_exists($key, $_SERVER),
            'server_value' => $_SERVER[$key] ?? null,
            'env_exists' => array_key_exists($key, $_ENV),
            'env_value' => $_ENV[$key] ?? null,
            'getenv_value' => getenv($key),
        ];

        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
        putenv($key.'='.$value);

        return $state;
    }

    /**
     * @param array{server_exists: bool, server_value: mixed, env_exists: bool, env_value: mixed, getenv_value: string|false} $state
     */
    private function restoreEnvironment(string $key, array $state): void
    {
        if ($state['server_exists']) {
            $_SERVER[$key] = $state['server_value'];
        } else {
            unset($_SERVER[$key]);
        }

        if ($state['env_exists']) {
            $_ENV[$key] = $state['env_value'];
        } else {
            unset($_ENV[$key]);
        }

        false === $state['getenv_value'] ? putenv($key) : putenv($key.'='.$state['getenv_value']);
    }
}
