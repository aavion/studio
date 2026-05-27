<?php

declare(strict_types=1);

namespace App\Tests\Core\Statistics;

use App\Core\Statistics\AccessStatisticsAggregator;
use App\Core\Statistics\AccessStatisticsWindow;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class AccessStatisticsAggregatorTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE access_statistic_event (
                uid VARCHAR(36) NOT NULL PRIMARY KEY,
                occurred_at DATETIME NOT NULL,
                request_id VARCHAR(64) NOT NULL,
                visitor_id VARCHAR(64) NOT NULL,
                method VARCHAR(16) NOT NULL,
                path VARCHAR(1024) NOT NULL,
                requested_path VARCHAR(1024) NOT NULL,
                route VARCHAR(190) NOT NULL,
                resolved_route VARCHAR(190) NOT NULL,
                surface VARCHAR(40) NOT NULL,
                http_status INTEGER NOT NULL,
                duration_ms INTEGER DEFAULT NULL,
                browser_family VARCHAR(40) NOT NULL,
                device_type VARCHAR(40) NOT NULL,
                is_bot BOOLEAN NOT NULL,
                referrer_host VARCHAR(255) NOT NULL,
                preferred_language VARCHAR(20) NOT NULL,
                request_content_type VARCHAR(120) NOT NULL,
                response_content_type VARCHAR(120) NOT NULL,
                response_size INTEGER DEFAULT NULL,
                city VARCHAR(80) NOT NULL,
                state VARCHAR(80) NOT NULL,
                country VARCHAR(80) NOT NULL,
                continent VARCHAR(80) NOT NULL,
                metadata CLOB NOT NULL
            )
            SQL);
    }

    public function testItAggregatesDatabaseStatisticsWithoutExposingVisitorIds(): void
    {
        $this->insertEvent('00000000-0000-0000-0000-000000000001', 'request-a', 'visitor-a', 'GET', '/', 'content_home', 'public', 200, 20, 'DE', 'safari', 'mobile', false, 'example.org', 'de-de', '2026-05-27 10:00:00');
        $this->insertEvent('00000000-0000-0000-0000-000000000002', 'request-b', 'visitor-a', 'GET', '/missing', 'content_view', 'public', 404, 40, 'DE', 'safari', 'mobile', false, 'example.org', 'de-de', '2026-05-27 10:00:00');
        $this->insertEvent('00000000-0000-0000-0000-000000000003', 'request-c', 'visitor-b', 'POST', '/admin', 'backend_admin_index', 'admin', 302, 60, 'n/a', 'bot', 'bot', true, 'n/a', 'en-us', '2026-05-27 10:00:00');

        $snapshot = (new AccessStatisticsAggregator($this->connection, new AccessStatisticsWindow()))->snapshot('all');
        $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);

        self::assertSame('all', $snapshot['window']);
        self::assertNull($snapshot['since']);
        self::assertSame(3, $snapshot['total_requests']);
        self::assertSame(2, $snapshot['unique_visitors']);
        self::assertSame(1, $snapshot['status_families']['2xx']);
        self::assertSame(1, $snapshot['status_families']['3xx']);
        self::assertSame(1, $snapshot['status_families']['4xx']);
        self::assertContains(['label' => 'content_home', 'count' => 1], $snapshot['top_routes']);
        self::assertSame([['label' => 'content_view', 'count' => 1]], $snapshot['top_not_found']);
        self::assertContains(['label' => 'DE', 'count' => 2], $snapshot['top_countries']);
        self::assertSame([['label' => 'safari', 'count' => 2], ['label' => 'bot', 'count' => 1]], $snapshot['top_browsers']);
        self::assertSame([['label' => 'mobile', 'count' => 2], ['label' => 'bot', 'count' => 1]], $snapshot['device_types']);
        self::assertSame(1, $snapshot['bot_requests']);
        self::assertSame([['label' => 'public', 'count' => 2], ['label' => 'admin', 'count' => 1]], $snapshot['surfaces']);
        self::assertSame([['label' => 'example.org', 'count' => 2]], $snapshot['top_referrers']);
        self::assertSame([['label' => 'de-de', 'count' => 2], ['label' => 'en-us', 'count' => 1]], $snapshot['languages']);
        self::assertSame(40, $snapshot['average_duration_ms']);
        self::assertStringNotContainsString('visitor-a', $encoded);
        self::assertStringNotContainsString('visitor-b', $encoded);
    }

    public function testItFiltersStatisticsByWindow(): void
    {
        $this->insertEvent('00000000-0000-0000-0000-000000000001', 'request-a', 'visitor-a', 'GET', '/', 'content_home', 'public', 200, 20, 'DE', 'safari', 'mobile', false, 'example.org', 'de-de', '2000-01-01 10:00:00');

        $snapshot = (new AccessStatisticsAggregator($this->connection, new AccessStatisticsWindow()))->snapshot('24h');

        self::assertSame('24h', $snapshot['window']);
        self::assertIsString($snapshot['since']);
        self::assertSame(0, $snapshot['total_requests']);
    }

    private function insertEvent(string $uid, string $requestId, string $visitorId, string $method, string $path, string $route, string $surface, int $status, int $durationMs, string $country, string $browserFamily, string $deviceType, bool $isBot, string $referrerHost, string $preferredLanguage, string $occurredAt): void
    {
        $this->connection->insert('access_statistic_event', [
            'uid' => $uid,
            'occurred_at' => $occurredAt,
            'request_id' => $requestId,
            'visitor_id' => $visitorId,
            'method' => $method,
            'path' => $path,
            'requested_path' => $path,
            'route' => $route,
            'resolved_route' => $route,
            'surface' => $surface,
            'http_status' => $status,
            'duration_ms' => $durationMs,
            'browser_family' => $browserFamily,
            'device_type' => $deviceType,
            'is_bot' => $isBot,
            'referrer_host' => $referrerHost,
            'preferred_language' => $preferredLanguage,
            'request_content_type' => 'n/a',
            'response_content_type' => 'text/html',
            'response_size' => 100,
            'city' => 'n/a',
            'state' => 'n/a',
            'country' => $country,
            'continent' => 'n/a',
            'metadata' => '{}',
        ]);
    }
}
