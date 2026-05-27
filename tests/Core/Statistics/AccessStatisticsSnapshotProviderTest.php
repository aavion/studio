<?php

declare(strict_types=1);

namespace App\Tests\Core\Statistics;

use App\Core\Statistics\AccessStatisticsAggregator;
use App\Core\Statistics\AccessStatisticsSnapshotProvider;
use App\Core\Statistics\FileAccessStatisticsStore;
use App\Tests\Support\FilesystemTestHelper;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class AccessStatisticsSnapshotProviderTest extends TestCase
{
    use FilesystemTestHelper;

    private string $root;

    protected function setUp(): void
    {
        $this->root = $this->createTemporaryDirectory('studio-access-statistics-provider');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testItWritesAndReturnsStoredSnapshotFromDatabaseStatistics(): void
    {
        $connection = $this->connection();
        $connection->insert('access_statistic_event', [
            'uid' => '00000000-0000-0000-0000-000000000001',
            'occurred_at' => '2026-05-27 10:00:00',
            'visitor_id' => 'visitor-a',
            'method' => 'GET',
            'path' => '/',
            'route' => 'content_home',
            'http_status' => 200,
            'city' => 'n/a',
            'state' => 'n/a',
            'country' => 'DE',
            'continent' => 'n/a',
            'metadata' => '{}',
        ]);

        $provider = new AccessStatisticsSnapshotProvider(
            new AccessStatisticsAggregator($connection),
            new FileAccessStatisticsStore($this->root.'/statistics', 'test'),
        );

        $snapshot = $provider->snapshot();
        $stored = json_decode((string) file_get_contents($this->root.'/statistics/test/access/latest.json'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(1, $snapshot['total_requests']);
        self::assertSame($stored, $snapshot);
        self::assertStringNotContainsString('visitor-a', json_encode($stored, JSON_THROW_ON_ERROR));
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(<<<'SQL'
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

        return $connection;
    }
}
