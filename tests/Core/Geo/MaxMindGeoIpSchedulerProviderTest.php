<?php

declare(strict_types=1);

namespace App\Tests\Core\Geo;

use App\Core\Config\Config;
use App\Core\Geo\GeoIpMessageCode;
use App\Core\Geo\MaxMindGeoIpArchiveExtractorInterface;
use App\Core\Geo\MaxMindGeoIpConfig;
use App\Core\Geo\MaxMindGeoIpDatabaseReaderFactoryInterface;
use App\Core\Geo\MaxMindGeoIpDatabaseReaderInterface;
use App\Core\Geo\MaxMindGeoIpDatabaseUpdater;
use App\Core\Geo\MaxMindGeoIpDownloadClientInterface;
use App\Core\Geo\MaxMindGeoIpSchedulerProvider;
use App\Core\Workflow\WorkflowResult;
use App\Scheduler\SchedulerTaskType;
use Doctrine\DBAL\DriverManager;
use GeoIp2\Model\City;
use MaxMind\Db\Reader\Metadata;
use PHPUnit\Framework\TestCase;

final class MaxMindGeoIpSchedulerProviderTest extends TestCase
{
    public function testItRegistersDailyTrustedCallableTask(): void
    {
        $provider = new MaxMindGeoIpSchedulerProvider($this->updater());
        $task = $provider->schedulerTasks()[0];

        self::assertSame('system.geoip2_database_update', $task->identifier());
        self::assertSame(SchedulerTaskType::Callable, $task->type());
        self::assertSame('system.geoip2.database_update', $task->target());
        self::assertSame('0 3 * * *', $task->defaultCronExpression());
        self::assertTrue($task->trusted());
    }

    public function testCallableReportsMissingLicenseKeyAsFailure(): void
    {
        $provider = new MaxMindGeoIpSchedulerProvider($this->updater());
        $callable = $provider->schedulerCallable('system.geoip2.database_update');

        self::assertNotNull($callable);

        $execution = $callable();

        self::assertFalse($execution->isSuccess());
        self::assertSame(GeoIpMessageCode::GEOIP_DOWNLOAD_MISSING_LICENSE_KEY, $execution->messages()[0]->code());
    }

    private function updater(): MaxMindGeoIpDatabaseUpdater
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');

        return new MaxMindGeoIpDatabaseUpdater(
            new MaxMindGeoIpConfig(new Config($connection)),
            new SchedulerGeoIpDownloadClient(),
            new SchedulerGeoIpArchiveExtractor(),
            new SchedulerGeoIpReaderFactory(),
            sys_get_temp_dir(),
        );
    }
}

final readonly class SchedulerGeoIpDownloadClient implements MaxMindGeoIpDownloadClientInterface
{
    public function download(string $url, string $targetPath): WorkflowResult
    {
        file_put_contents($targetPath, 'archive');

        return WorkflowResult::success();
    }
}

final readonly class SchedulerGeoIpArchiveExtractor implements MaxMindGeoIpArchiveExtractorInterface
{
    public function extractDatabase(string $archivePath, string $workspaceDir): WorkflowResult
    {
        $databasePath = $workspaceDir.DIRECTORY_SEPARATOR.'GeoLite2-City.mmdb';
        file_put_contents($databasePath, 'database');

        return WorkflowResult::success(['database_path' => $databasePath]);
    }
}

final readonly class SchedulerGeoIpReaderFactory implements MaxMindGeoIpDatabaseReaderFactoryInterface
{
    public function open(string $databasePath, array $locales): MaxMindGeoIpDatabaseReaderInterface
    {
        return new SchedulerGeoIpReader();
    }
}

final readonly class SchedulerGeoIpReader implements MaxMindGeoIpDatabaseReaderInterface
{
    public function city(string $ipAddress): City
    {
        return new City([]);
    }

    public function metadata(): Metadata
    {
        return new Metadata([
            'binary_format_major_version' => 2,
            'binary_format_minor_version' => 0,
            'build_epoch' => 1781481600,
            'database_type' => 'GeoLite2-City',
            'languages' => ['en'],
            'description' => ['en' => 'Test database'],
            'ip_version' => 6,
            'node_count' => 1,
            'record_size' => 24,
        ]);
    }
}
