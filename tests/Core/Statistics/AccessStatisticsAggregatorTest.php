<?php

declare(strict_types=1);

namespace App\Tests\Core\Statistics;

use App\Core\Statistics\AccessStatisticsAggregator;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;

final class AccessStatisticsAggregatorTest extends TestCase
{
    use FilesystemTestHelper;

    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = $this->createTemporaryDirectory('studio-access-statistics');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->logDir);
    }

    public function testItAggregatesAccessLogsWithoutExposingIps(): void
    {
        $this->writeTestFile($this->logDir, 'test.studio-access-2026-05-27.log', implode(PHP_EOL, [
            '[2026-05-27T10:00:00.000000+00:00] studio_access.INFO: access.request {"method":"GET","path":"/","route":"content_home","http_status":200,"ip":"203.0.113.10","country":"DE"} []',
            '[2026-05-27T10:01:00.000000+00:00] studio_access.INFO: access.request {"method":"GET","path":"/missing","route":"content_view","http_status":404,"ip":"203.0.113.11","country":"DE"} []',
            '[2026-05-27T10:02:00.000000+00:00] studio_access.INFO: access.request {"method":"POST","path":"/admin","route":"backend_admin_index","http_status":302,"ip":"203.0.113.12","country":"n/a"} []',
            '',
        ]));

        $snapshot = (new AccessStatisticsAggregator($this->logDir, 'test'))->snapshot();
        $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);

        self::assertSame(3, $snapshot['total_requests']);
        self::assertSame(1, $snapshot['status_families']['2xx']);
        self::assertSame(1, $snapshot['status_families']['3xx']);
        self::assertSame(1, $snapshot['status_families']['4xx']);
        self::assertContains(['label' => 'content_home', 'count' => 1], $snapshot['top_routes']);
        self::assertSame([['label' => 'content_view', 'count' => 1]], $snapshot['top_not_found']);
        self::assertContains(['label' => 'DE', 'count' => 2], $snapshot['top_countries']);
        self::assertStringNotContainsString('203.0.113', $encoded);
    }
}
