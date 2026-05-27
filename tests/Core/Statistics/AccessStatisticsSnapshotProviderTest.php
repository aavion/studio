<?php

declare(strict_types=1);

namespace App\Tests\Core\Statistics;

use App\Core\Statistics\AccessStatisticsAggregator;
use App\Core\Statistics\AccessStatisticsSnapshotProvider;
use App\Core\Statistics\FileAccessStatisticsStore;
use App\Tests\Support\FilesystemTestHelper;
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

    public function testItWritesAndReturnsStoredSnapshotFromRawAccessLogs(): void
    {
        mkdir($this->root.'/logs', 0777, true);
        $this->writeTestFile($this->root.'/logs', 'test.studio-access-2026-05-27.log', '[2026-05-27T10:00:00.000000+00:00] studio_access.INFO: access.request {"method":"GET","path":"/","route":"content_home","http_status":200,"ip":"203.0.113.10","country":"DE"} []'.PHP_EOL);

        $provider = new AccessStatisticsSnapshotProvider(
            new AccessStatisticsAggregator($this->root.'/logs', 'test'),
            new FileAccessStatisticsStore($this->root.'/statistics', 'test'),
        );

        $snapshot = $provider->snapshot();
        $stored = json_decode((string) file_get_contents($this->root.'/statistics/test/access/latest.json'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(1, $snapshot['total_requests']);
        self::assertSame($stored, $snapshot);
        self::assertStringNotContainsString('203.0.113.', json_encode($stored, JSON_THROW_ON_ERROR));
    }
}
