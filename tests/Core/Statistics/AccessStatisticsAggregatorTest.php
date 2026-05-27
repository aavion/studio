<?php

declare(strict_types=1);

namespace App\Tests\Core\Statistics;

use App\Core\Statistics\AccessStatisticsAggregator;
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
                visitor_id VARCHAR(64) NOT NULL,
                method VARCHAR(16) NOT NULL,
                path VARCHAR(1024) NOT NULL,
                route VARCHAR(190) NOT NULL,
                http_status INTEGER NOT NULL,
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
        $this->insertEvent('00000000-0000-0000-0000-000000000001', 'visitor-a', 'GET', '/', 'content_home', 200, 'DE');
        $this->insertEvent('00000000-0000-0000-0000-000000000002', 'visitor-a', 'GET', '/missing', 'content_view', 404, 'DE');
        $this->insertEvent('00000000-0000-0000-0000-000000000003', 'visitor-b', 'POST', '/admin', 'backend_admin_index', 302, 'n/a');

        $snapshot = (new AccessStatisticsAggregator($this->connection))->snapshot();
        $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);

        self::assertSame(3, $snapshot['total_requests']);
        self::assertSame(2, $snapshot['unique_visitors']);
        self::assertSame(1, $snapshot['status_families']['2xx']);
        self::assertSame(1, $snapshot['status_families']['3xx']);
        self::assertSame(1, $snapshot['status_families']['4xx']);
        self::assertContains(['label' => 'content_home', 'count' => 1], $snapshot['top_routes']);
        self::assertSame([['label' => 'content_view', 'count' => 1]], $snapshot['top_not_found']);
        self::assertContains(['label' => 'DE', 'count' => 2], $snapshot['top_countries']);
        self::assertStringNotContainsString('visitor-a', $encoded);
        self::assertStringNotContainsString('visitor-b', $encoded);
    }

    private function insertEvent(string $uid, string $visitorId, string $method, string $path, string $route, int $status, string $country): void
    {
        $this->connection->insert('access_statistic_event', [
            'uid' => $uid,
            'occurred_at' => '2026-05-27 10:00:00',
            'visitor_id' => $visitorId,
            'method' => $method,
            'path' => $path,
            'route' => $route,
            'http_status' => $status,
            'city' => 'n/a',
            'state' => 'n/a',
            'country' => $country,
            'continent' => 'n/a',
            'metadata' => '{}',
        ]);
    }
}
