<?php

declare(strict_types=1);

namespace App\Tests\Core\Statistics;

use App\Core\Statistics\AccessStatisticsAggregator;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Statistics\AccessStatisticsPolicy;
use App\Core\Statistics\AccessStatisticsSnapshotProvider;
use App\Core\Statistics\AccessStatisticsWindow;
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
            'uid' => '00000000-0000-7000-8000-000000000001',
            'occurred_at' => '2026-05-27 10:00:00',
            'request_id' => 'request-a',
            'visitor_id' => 'visitor-a',
            'method' => 'GET',
            'path' => '/',
            'requested_path' => '/',
            'route' => 'content_home',
            'resolved_route' => 'content_home',
            'surface' => 'public',
            'http_status' => 200,
            'duration_ms' => 25,
            'browser_family' => 'firefox',
            'device_type' => 'desktop',
            'is_bot' => false,
            'do_not_track' => false,
            'referrer_host' => 'example.org',
            'preferred_language' => 'en-us',
            'request_content_type' => 'n/a',
            'response_content_type' => 'text/html',
            'response_size' => 100,
            'city' => 'n/a',
            'state' => 'n/a',
            'country' => 'DE',
            'continent' => 'n/a',
            'metadata' => '{}',
        ]);

        $provider = new AccessStatisticsSnapshotProvider(
            new AccessStatisticsAggregator($connection, new AccessStatisticsWindow()),
            new FileAccessStatisticsStore($this->root.'/statistics', 'test'),
            new AccessStatisticsWindow(),
        );

        $snapshot = $provider->snapshot('all');
        $stored = json_decode((string) file_get_contents($this->root.'/statistics/test/access/latest.json'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('all', $snapshot['window']);
        self::assertSame(1, $snapshot['total_requests']);
        self::assertSame($stored, $snapshot);
        self::assertStringNotContainsString('visitor-a', json_encode($stored, JSON_THROW_ON_ERROR));
        self::assertSame(['1h', '24h', '7d', '30d', 'all'], array_column($provider->windows(), 'key'));
    }

    public function testItReturnsDisabledSnapshotWhenStatisticsAreDisabled(): void
    {
        $connection = $this->connection();
        $config = new Config($connection);
        $config->set(AccessStatisticsPolicy::ENABLED_KEY, false, ConfigValueType::Boolean);

        $provider = new AccessStatisticsSnapshotProvider(
            new AccessStatisticsAggregator($connection, new AccessStatisticsWindow()),
            new FileAccessStatisticsStore($this->root.'/statistics', 'test'),
            new AccessStatisticsWindow(),
            new AccessStatisticsPolicy($config),
        );

        $snapshot = $provider->snapshot('7d');

        self::assertFalse($snapshot['enabled']);
        self::assertSame('7d', $snapshot['window']);
        self::assertSame(0, $snapshot['total_requests']);
        self::assertFileDoesNotExist($this->root.'/statistics/test/access/latest.json');
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(<<<'SQL'
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
                do_not_track BOOLEAN NOT NULL,
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
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL, modified_at DATETIME NOT NULL, modified_by VARCHAR(180) DEFAULT NULL)');

        return $connection;
    }
}
