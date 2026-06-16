<?php

declare(strict_types=1);

namespace App\Tests\Core\Log;

use App\Core\Log\DatabaseLogBrowser;
use App\Core\Log\LogFileBrowser;
use App\Tests\Support\FilesystemTestHelper;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class DatabaseLogBrowserTest extends TestCase
{
    use FilesystemTestHelper;

    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = $this->createTemporaryDirectory('system-database-log-browser');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->logDir);
    }

    public function testItBrowsesDatabaseBackedLogSourcesAndEntries(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE message_log_entry (uid VARCHAR(36) PRIMARY KEY NOT NULL, occurred_at DATETIME NOT NULL, level VARCHAR(16) NOT NULL, message VARCHAR(255) NOT NULL, code VARCHAR(160) DEFAULT NULL, context CLOB NOT NULL)');
        $connection->executeStatement('CREATE TABLE audit_log_entry (uid VARCHAR(36) PRIMARY KEY NOT NULL, occurred_at DATETIME NOT NULL, user_name VARCHAR(180) NOT NULL, user_uid VARCHAR(36) DEFAULT NULL, user_access_level INTEGER NOT NULL, action VARCHAR(160) NOT NULL, request_id VARCHAR(64) NOT NULL, visitor_id VARCHAR(64) NOT NULL, requested_path VARCHAR(1024) NOT NULL, resolved_route VARCHAR(190) NOT NULL, context CLOB NOT NULL)');
        $connection->executeStatement('CREATE TABLE access_log_entry (uid VARCHAR(36) PRIMARY KEY NOT NULL, occurred_at DATETIME NOT NULL, request_id VARCHAR(64) NOT NULL, correlation_id VARCHAR(64) NOT NULL, method VARCHAR(16) NOT NULL, path VARCHAR(1024) NOT NULL, requested_path VARCHAR(1024) NOT NULL, route VARCHAR(190) NOT NULL, resolved_route VARCHAR(190) NOT NULL, surface VARCHAR(40) NOT NULL, query_string VARCHAR(1024) NOT NULL, http_status INTEGER NOT NULL, duration_ms INTEGER DEFAULT NULL, visitor_id VARCHAR(64) NOT NULL, scheme VARCHAR(10) NOT NULL, host VARCHAR(255) NOT NULL, client_ip VARCHAR(45) NOT NULL, proxy_client_ip VARCHAR(45) NOT NULL, user_agent VARCHAR(500) NOT NULL, referrer VARCHAR(1024) NOT NULL, referrer_host VARCHAR(255) NOT NULL, accept_language VARCHAR(255) NOT NULL, preferred_language VARCHAR(20) NOT NULL, request_content_type VARCHAR(120) NOT NULL, response_content_type VARCHAR(120) NOT NULL, response_size INTEGER DEFAULT NULL, city VARCHAR(80) NOT NULL, state VARCHAR(80) NOT NULL, country VARCHAR(80) NOT NULL, continent VARCHAR(80) NOT NULL, context CLOB NOT NULL)');
        $connection->executeStatement('CREATE TABLE security_signal_event (uid VARCHAR(36) PRIMARY KEY NOT NULL, occurred_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, signal_type VARCHAR(80) NOT NULL, reason_code VARCHAR(120) NOT NULL, severity VARCHAR(16) NOT NULL, confidence INTEGER NOT NULL, subject_type VARCHAR(40) NOT NULL, subject_identifier VARCHAR(190) NOT NULL, ip_derived BOOLEAN NOT NULL, request_family VARCHAR(40) NOT NULL, request_intent VARCHAR(80) NOT NULL, request_id VARCHAR(64) NOT NULL, visitor_id VARCHAR(64) NOT NULL, path VARCHAR(1024) NOT NULL, route VARCHAR(190) NOT NULL, http_status INTEGER DEFAULT NULL, context CLOB NOT NULL)');
        $connection->insert('message_log_entry', [
            'uid' => '99999999-0000-7000-8000-000000000001',
            'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'level' => 'INFO',
            'message' => 'message.test',
            'code' => 'test.message',
            'context' => '{"code":"test.message"}',
        ]);
        $connection->insert('security_signal_event', [
            'uid' => '99999999-0000-7000-8000-000000000002',
            'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'expires_at' => (new \DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s'),
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
        $connection->insert('access_log_entry', [
            'uid' => '99999999-0000-7000-8000-000000000003',
            'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
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
            'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
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
        $this->writeTestFile($this->logDir, 'test.log', '[2099-01-01T10:00:00.000000+00:00] app.ERROR: app.failure {"code":"app.failure","request_id":"application-request"} []'.PHP_EOL);

        $browser = new DatabaseLogBrowser($connection, new LogFileBrowser($this->logDir, 'test'));
        $applicationView = $browser->browse(['source' => 'application', 'level' => 'ERROR', 'q' => 'application-request']);
        self::assertTrue($applicationView['capabilities']['level_filter']);
        self::assertSame(1, $applicationView['pagination']['total']);
        self::assertSame('application', $applicationView['entries'][0]['source']);
        self::assertSame('app.failure', $applicationView['entries'][0]['message']);
        $applicationEntry = $browser->entry('application', $applicationView['entries'][0]['id']);
        self::assertNotNull($applicationEntry);
        self::assertSame('app.failure', $applicationEntry['message']);

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

        self::assertSame(['application', 'message', 'audit', 'access', 'security_signal'], array_column($view['sources'], 'key'));
        self::assertSame('security_signal', $view['selected_source']);
        self::assertSame(1, $view['pagination']['total']);
        self::assertSame('99999999-0000-7000-8000-000000000002', $view['entries'][0]['id']);
        self::assertSame('probe: security.probe.env', $view['entries'][0]['summary']);
        self::assertSame('visitor-1', $view['entries'][0]['context']['subject_identifier']);

        $entry = $browser->entry('message', '99999999-0000-7000-8000-000000000001');

        self::assertNotNull($entry);
        self::assertSame('message.test', $entry['message']);
    }
}
