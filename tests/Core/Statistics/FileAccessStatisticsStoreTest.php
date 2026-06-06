<?php

declare(strict_types=1);

namespace App\Tests\Core\Statistics;

use App\Core\Statistics\FileAccessStatisticsStore;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;

final class FileAccessStatisticsStoreTest extends TestCase
{
    use FilesystemTestHelper;

    private string $statisticsDir;

    protected function setUp(): void
    {
        $this->statisticsDir = $this->createTemporaryDirectory('system-access-statistics-store');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->statisticsDir);
    }

    public function testItStoresLatestAnonymizedSnapshotInEnvironmentDirectory(): void
    {
        $store = new FileAccessStatisticsStore($this->statisticsDir, 'test');
        $snapshot = [
            'generated_at' => '2026-05-27T00:00:00+00:00',
            'total_requests' => 2,
            'status_families' => ['2xx' => 1, '4xx' => 1],
            'top_routes' => [['label' => 'content_home', 'count' => 1]],
        ];

        self::assertTrue($store->saveLatest($snapshot));
        self::assertSame($snapshot, $store->latest());
        self::assertFileExists($this->statisticsDir.'/test/access/latest.json');
        self::assertStringNotContainsString('203.0.113.', (string) file_get_contents($this->statisticsDir.'/test/access/latest.json'));
    }

    public function testItReturnsNullWhenNoSnapshotExists(): void
    {
        $store = new FileAccessStatisticsStore($this->statisticsDir, 'test');

        self::assertNull($store->latest());
    }

    public function testItNormalizesTrailingDirectorySeparators(): void
    {
        $store = new FileAccessStatisticsStore($this->statisticsDir.'\\', 'test');
        $snapshot = ['generated_at' => '2026-05-27T00:00:00+00:00'];

        self::assertTrue($store->saveLatest($snapshot));
        self::assertSame($snapshot, $store->latest());
        self::assertFileExists($this->statisticsDir.'/test/access/latest.json');
    }
}
