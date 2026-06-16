<?php

declare(strict_types=1);

namespace App\Tests\Core\Log;

use App\Core\Log\DatabaseLogProjector;
use App\Core\Log\DatabaseLogRetentionPolicy;
use App\Database\DatabaseReadyState;
use App\Setup\SetupCompletionMarker;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class DatabaseLogProjectorTest extends TestCase
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
            $projector = new DatabaseLogProjector(
                $connection,
                new DatabaseLogRetentionPolicy($connection),
                new DatabaseReadyState(new SetupCompletionMarker(), sys_get_temp_dir().'/missing-system-project', 'test'),
            );

            $projector->recordAccess([
                'request_id' => 'setup-request',
                'method' => 'GET',
                'path' => '/setup',
            ]);
        } finally {
            $this->restoreEnvironment(SetupCompletionMarker::KEY, $setupState);
            $this->restoreEnvironment(DatabaseReadyState::ALLOW_UNREADY_KEY, $allowState);
        }
    }

    public function testItWritesAndPurgesDatabaseLogProjectionRows(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createTables($connection);
        $connection->insert('config_entry', [
            'config_key' => DatabaseLogRetentionPolicy::ACCESS_LOG_RETENTION_DAYS_KEY,
            'value' => '1',
            'value_type' => 'integer',
            'sensitive' => 0,
            'modified_at' => '2026-06-16 00:00:00',
            'modified_by' => 'test',
        ]);
        $connection->insert('access_log_entry', [
            'uid' => '99999999-0000-7000-8000-000000000001',
            'occurred_at' => '2000-01-01 00:00:00',
            'request_id' => 'old',
            'correlation_id' => 'n/a',
            'method' => 'GET',
            'path' => '/old',
            'requested_path' => '/old',
            'route' => 'old',
            'resolved_route' => 'old',
            'surface' => 'public',
            'query_string' => '',
            'http_status' => 200,
            'duration_ms' => null,
            'visitor_id' => 'old-visitor',
            'scheme' => 'https',
            'host' => 'example.test',
            'client_ip' => '127.0.0.1',
            'proxy_client_ip' => 'n/a',
            'user_agent' => 'Old Browser',
            'referrer' => 'n/a',
            'referrer_host' => 'n/a',
            'accept_language' => 'en',
            'preferred_language' => 'en',
            'request_content_type' => 'n/a',
            'response_content_type' => 'text/html',
            'response_size' => null,
            'city' => 'n/a',
            'state' => 'n/a',
            'country' => 'n/a',
            'continent' => 'n/a',
            'context' => '{}',
        ]);

        $projector = new DatabaseLogProjector(
            $connection,
            new DatabaseLogRetentionPolicy($connection),
            clock: new MockClock('2026-06-16 12:00:00'),
        );
        $projector->recordAccess([
            'request_id' => 'current',
            'method' => 'GET',
            'path' => '/admin/logs',
            'requested_path' => '/admin/logs',
            'route' => 'backend_admin_route',
            'resolved_route' => 'backend_admin_route',
            'http_status' => 200,
            'client_ip' => '203.0.113.1',
            'city' => str_repeat('x', 120),
        ]);

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM access_log_entry'));
        self::assertSame('current', $connection->fetchOne('SELECT request_id FROM access_log_entry'));
        self::assertSame('2026-06-16 12:00:00', $connection->fetchOne('SELECT occurred_at FROM access_log_entry'));
        self::assertSame(80, strlen((string) $connection->fetchOne('SELECT city FROM access_log_entry')));
    }

    private function createTables(\Doctrine\DBAL\Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) PRIMARY KEY NOT NULL, value CLOB NOT NULL, value_type VARCHAR(255) NOT NULL, sensitive BOOLEAN NOT NULL, modified_at DATETIME NOT NULL, modified_by VARCHAR(180) DEFAULT NULL)');
        $connection->executeStatement('CREATE TABLE access_log_entry (uid VARCHAR(36) PRIMARY KEY NOT NULL, occurred_at DATETIME NOT NULL, request_id VARCHAR(64) NOT NULL, correlation_id VARCHAR(64) NOT NULL, method VARCHAR(16) NOT NULL, path VARCHAR(1024) NOT NULL, requested_path VARCHAR(1024) NOT NULL, route VARCHAR(190) NOT NULL, resolved_route VARCHAR(190) NOT NULL, surface VARCHAR(40) NOT NULL, query_string VARCHAR(1024) NOT NULL, http_status INTEGER NOT NULL, duration_ms INTEGER DEFAULT NULL, visitor_id VARCHAR(64) NOT NULL, scheme VARCHAR(10) NOT NULL, host VARCHAR(255) NOT NULL, client_ip VARCHAR(45) NOT NULL, proxy_client_ip VARCHAR(45) NOT NULL, user_agent VARCHAR(500) NOT NULL, referrer VARCHAR(1024) NOT NULL, referrer_host VARCHAR(255) NOT NULL, accept_language VARCHAR(255) NOT NULL, preferred_language VARCHAR(20) NOT NULL, request_content_type VARCHAR(120) NOT NULL, response_content_type VARCHAR(120) NOT NULL, response_size INTEGER DEFAULT NULL, city VARCHAR(80) NOT NULL, state VARCHAR(80) NOT NULL, country VARCHAR(80) NOT NULL, continent VARCHAR(80) NOT NULL, context CLOB NOT NULL)');
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
