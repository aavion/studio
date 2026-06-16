<?php

declare(strict_types=1);

namespace App\Tests\Core\Log;

use App\Core\Log\DatabaseLogBrowser;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class DatabaseLogBrowserTest extends TestCase
{
    public function testItBrowsesDatabaseBackedLogSourcesAndEntries(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createTables($connection);
        $now = '2026-06-16 12:00:00';
        $connection->insert('message_log_entry', [
            'uid' => '99999999-0000-7000-8000-000000000001',
            'occurred_at' => $now,
            'level' => 'INFO',
            'message' => 'message.test',
            'code' => 'test.message',
            'context' => '{"code":"test.message"}',
        ]);
        $connection->insert('security_signal_event', [
            'uid' => '99999999-0000-7000-8000-000000000002',
            'occurred_at' => $now,
            'expires_at' => '2026-06-17 12:00:00',
            'signal_type' => 'probe',
            'reason_code' => 'security.probe.env',
            'severity' => 'WARNING',
            'confidence' => 90,
            'subject_type' => 'visitor',
            'subject_identifier' => 'visitor-1',
            'ip_derived' => 0,
            'request_family' => 'browser',
            'request_intent' => 'suspicious_probe',
            'request_id' => 'request-1',
            'visitor_id' => 'visitor-1',
            'path' => '/.env',
            'route' => 'n/a',
            'http_status' => 400,
            'context' => '{"source":"security.probe.env"}',
        ]);
        $connection->insert('security_signal_event', [
            'uid' => '99999999-0000-7000-8000-000000000005',
            'occurred_at' => $now,
            'expires_at' => '2026-06-16 11:59:59',
            'signal_type' => 'probe',
            'reason_code' => 'security.probe.expired',
            'severity' => 'WARNING',
            'confidence' => 90,
            'subject_type' => 'visitor',
            'subject_identifier' => 'visitor-expired',
            'ip_derived' => 0,
            'request_family' => 'browser',
            'request_intent' => 'suspicious_probe',
            'request_id' => 'request-expired',
            'visitor_id' => 'visitor-expired',
            'path' => '/.git/config',
            'route' => 'n/a',
            'http_status' => 400,
            'context' => '{"source":"security.probe.expired"}',
        ]);
        $connection->insert('access_log_entry', [
            'uid' => '99999999-0000-7000-8000-000000000003',
            'occurred_at' => $now,
            'request_id' => 'request-access',
            'correlation_id' => 'n/a',
            'method' => 'GET',
            'path' => '/admin/logs',
            'requested_path' => '/admin/logs',
            'route' => 'backend_admin_route',
            'resolved_route' => 'backend_admin_route',
            'surface' => 'admin',
            'query_string' => '',
            'http_status' => 200,
            'duration_ms' => null,
            'visitor_id' => 'visitor-hidden-search',
            'scheme' => 'https',
            'host' => 'example.test',
            'client_ip' => '203.0.113.10',
            'proxy_client_ip' => 'n/a',
            'user_agent' => 'Example Browser',
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
            'context' => '{"hidden":"visitor-hidden-search"}',
        ]);
        $connection->insert('audit_log_entry', [
            'uid' => '99999999-0000-7000-8000-000000000004',
            'occurred_at' => $now,
            'user_name' => 'admin',
            'user_uid' => '99999999-0000-7000-8000-000000000100',
            'user_access_level' => 8,
            'action' => 'audit.test',
            'request_id' => 'request-audit',
            'visitor_id' => 'visitor-audit',
            'requested_path' => '/admin/users',
            'resolved_route' => 'backend_admin_route',
            'context' => '{"hidden":"visitor-audit"}',
        ]);
        $browser = new DatabaseLogBrowser($connection, clock: new MockClock($now));
        $defaultView = $browser->browse(['source' => 'message']);
        self::assertSame(0, $defaultView['pagination']['total']);

        $infoView = $browser->browse(['source' => 'message', 'level' => 'INFO']);
        self::assertSame(1, $infoView['pagination']['total']);
        self::assertSame(['INFO'], $infoView['filters']['levels']);

        $accessView = $browser->browse(['source' => 'access', 'q' => 'visitor-hidden-search']);
        self::assertFalse($accessView['capabilities']['level_filter']);
        self::assertSame([], $accessView['filters']['levels']);
        self::assertSame(1, $accessView['pagination']['total']);
        self::assertSame('99999999-0000-7000-8000-000000000003', $accessView['entries'][0]['id']);

        $auditView = $browser->browse(['source' => 'audit', 'level' => 'ERROR', 'q' => 'visitor-audit']);
        self::assertFalse($auditView['capabilities']['level_filter']);
        self::assertSame([], $auditView['filters']['levels']);
        self::assertSame(1, $auditView['pagination']['total']);
        self::assertSame('99999999-0000-7000-8000-000000000004', $auditView['entries'][0]['id']);

        $view = $browser->browse(['source' => 'security_signal', 'q' => 'security.probe.env']);
        self::assertTrue($view['capabilities']['level_filter']);
        self::assertTrue($view['capabilities']['signal_reason_filter']);

        self::assertSame(['message', 'audit', 'access', 'security_signal'], array_column($view['sources'], 'key'));
        self::assertSame('security_signal', $view['selected_source']);
        self::assertSame(1, $view['pagination']['total']);
        self::assertSame('99999999-0000-7000-8000-000000000002', $view['entries'][0]['id']);
        self::assertSame('probe: security.probe.env', $view['entries'][0]['summary']);
        self::assertSame('visitor-1', $view['entries'][0]['context']['subject_identifier']);
        self::assertSame(0, $browser->browse(['source' => 'security_signal', 'q' => 'security.probe.expired'])['pagination']['total']);
        self::assertNull($browser->entry('security_signal', '99999999-0000-7000-8000-000000000005'));

        $entry = $browser->entry('message', '99999999-0000-7000-8000-000000000001');

        self::assertNotNull($entry);
        self::assertSame('message.test', $entry['message']);
    }

    public function testItCapsFormerAllPageSizeAtFiveHundredRowsWithPagination(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE message_log_entry (uid VARCHAR(36) PRIMARY KEY NOT NULL, occurred_at DATETIME NOT NULL, level VARCHAR(16) NOT NULL, message VARCHAR(255) NOT NULL, code VARCHAR(160) DEFAULT NULL, context CLOB NOT NULL)');
        $now = '2026-06-16 12:00:00';

        for ($i = 1; $i <= 501; ++$i) {
            $connection->insert('message_log_entry', [
                'uid' => sprintf('99999999-0000-7000-8000-%012d', $i),
                'occurred_at' => $now,
                'level' => 'NOTICE',
                'message' => 'message.test',
                'code' => 'test.message',
                'context' => '{"code":"test.message"}',
            ]);
        }

        $view = (new DatabaseLogBrowser($connection, clock: new MockClock($now)))->browse([
            'source' => 'message',
            'per_page' => 'all',
            'page' => 999,
        ]);

        self::assertSame(500, $view['filters']['per_page']);
        self::assertSame(2, $view['filters']['page']);
        self::assertSame(501, $view['pagination']['total']);
        self::assertSame(2, $view['pagination']['total_pages']);
        self::assertFalse($view['pagination']['has_next']);
        self::assertCount(1, $view['entries']);
    }

    public function testItHonorsConfiguredDatabaseRetentionWhenBrowsing(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE message_log_entry (uid VARCHAR(36) PRIMARY KEY NOT NULL, occurred_at DATETIME NOT NULL, level VARCHAR(16) NOT NULL, message VARCHAR(255) NOT NULL, code VARCHAR(160) DEFAULT NULL, context CLOB NOT NULL)');
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(190) PRIMARY KEY NOT NULL, value CLOB NOT NULL)');
        $connection->insert('config_entry', [
            'config_key' => 'logging.database.message_retention_days',
            'value' => '1',
        ]);
        $connection->insert('message_log_entry', [
            'uid' => '99999999-0000-7000-8000-000000000001',
            'occurred_at' => '2026-06-16 12:00:00',
            'level' => 'NOTICE',
            'message' => 'message.current',
            'code' => 'test.current',
            'context' => '{}',
        ]);
        $connection->insert('message_log_entry', [
            'uid' => '99999999-0000-7000-8000-000000000002',
            'occurred_at' => '2026-06-14 12:00:00',
            'level' => 'NOTICE',
            'message' => 'message.expired_by_setting',
            'code' => 'test.expired',
            'context' => '{}',
        ]);

        $view = (new DatabaseLogBrowser($connection, clock: new MockClock('2026-06-16 12:00:00')))->browse([
            'source' => 'message',
            'time_window' => '30d',
        ]);

        self::assertSame(1, $view['pagination']['total']);
        self::assertSame('message.current', $view['entries'][0]['message']);
    }

    public function testItCastsJsonContextAndSearchesCaseInsensitivelyOnPostgreSql(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection
            ->expects(self::once())
            ->method('fetchOne')
            ->with(self::stringContains('LOWER(CAST(context AS TEXT)) LIKE ?'), self::anything())
            ->willReturn(0);
        $connection
            ->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(self::stringContains('LOWER(CAST(context AS TEXT)) LIKE ?'), self::callback(static fn (array $params): bool => in_array('%scanner%', $params, true)))
            ->willReturn([]);

        (new DatabaseLogBrowser($connection, clock: new MockClock('2026-06-16 12:00:00')))->browse([
            'source' => 'security_signal',
            'q' => 'Scanner',
        ]);
    }

    private function createTables(\Doctrine\DBAL\Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE message_log_entry (uid VARCHAR(36) PRIMARY KEY NOT NULL, occurred_at DATETIME NOT NULL, level VARCHAR(16) NOT NULL, message VARCHAR(255) NOT NULL, code VARCHAR(160) DEFAULT NULL, context CLOB NOT NULL)');
        $connection->executeStatement('CREATE TABLE audit_log_entry (uid VARCHAR(36) PRIMARY KEY NOT NULL, occurred_at DATETIME NOT NULL, user_name VARCHAR(180) NOT NULL, user_uid VARCHAR(36) DEFAULT NULL, user_access_level INTEGER NOT NULL, action VARCHAR(160) NOT NULL, request_id VARCHAR(64) NOT NULL, visitor_id VARCHAR(64) NOT NULL, requested_path VARCHAR(1024) NOT NULL, resolved_route VARCHAR(190) NOT NULL, context CLOB NOT NULL)');
        $connection->executeStatement('CREATE TABLE access_log_entry (uid VARCHAR(36) PRIMARY KEY NOT NULL, occurred_at DATETIME NOT NULL, request_id VARCHAR(64) NOT NULL, correlation_id VARCHAR(64) NOT NULL, method VARCHAR(16) NOT NULL, path VARCHAR(1024) NOT NULL, requested_path VARCHAR(1024) NOT NULL, route VARCHAR(190) NOT NULL, resolved_route VARCHAR(190) NOT NULL, surface VARCHAR(40) NOT NULL, query_string VARCHAR(1024) NOT NULL, http_status INTEGER NOT NULL, duration_ms INTEGER DEFAULT NULL, visitor_id VARCHAR(64) NOT NULL, scheme VARCHAR(10) NOT NULL, host VARCHAR(255) NOT NULL, client_ip VARCHAR(45) NOT NULL, proxy_client_ip VARCHAR(45) NOT NULL, user_agent VARCHAR(500) NOT NULL, referrer VARCHAR(1024) NOT NULL, referrer_host VARCHAR(255) NOT NULL, accept_language VARCHAR(255) NOT NULL, preferred_language VARCHAR(20) NOT NULL, request_content_type VARCHAR(120) NOT NULL, response_content_type VARCHAR(120) NOT NULL, response_size INTEGER DEFAULT NULL, city VARCHAR(80) NOT NULL, state VARCHAR(80) NOT NULL, country VARCHAR(80) NOT NULL, continent VARCHAR(80) NOT NULL, context CLOB NOT NULL)');
        $connection->executeStatement('CREATE TABLE security_signal_event (uid VARCHAR(36) PRIMARY KEY NOT NULL, occurred_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, signal_type VARCHAR(80) NOT NULL, reason_code VARCHAR(120) NOT NULL, severity VARCHAR(16) NOT NULL, confidence INTEGER NOT NULL, subject_type VARCHAR(40) NOT NULL, subject_identifier VARCHAR(190) NOT NULL, ip_derived BOOLEAN NOT NULL, request_family VARCHAR(40) NOT NULL, request_intent VARCHAR(80) NOT NULL, request_id VARCHAR(64) NOT NULL, visitor_id VARCHAR(64) NOT NULL, path VARCHAR(1024) NOT NULL, route VARCHAR(190) NOT NULL, http_status INTEGER DEFAULT NULL, context CLOB NOT NULL)');
    }
}
