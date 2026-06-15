<?php

declare(strict_types=1);

namespace App\Tests\Core\Geo;

use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Geo\GeoIpMessageCode;
use App\Core\Geo\GeoIpMessageKey;
use App\Core\Geo\MaxMindGeoIpArchiveExtractorInterface;
use App\Core\Geo\MaxMindGeoIpConfig;
use App\Core\Geo\MaxMindGeoIpDatabaseReaderFactoryInterface;
use App\Core\Geo\MaxMindGeoIpDatabaseReaderInterface;
use App\Core\Geo\MaxMindGeoIpDatabaseUpdater;
use App\Core\Geo\MaxMindGeoIpDownloadClientInterface;
use App\Core\Message\Message;
use App\Core\Workflow\WorkflowResult;
use App\Tests\Support\FilesystemTestHelper;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use GeoIp2\Model\City;
use MaxMind\Db\Reader\Metadata;
use PHPUnit\Framework\TestCase;

final class MaxMindGeoIpDatabaseUpdaterTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = $this->createTemporaryDirectory('maxmind-geoip-updater');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testItFailsWithoutLicenseKey(): void
    {
        $updater = new MaxMindGeoIpDatabaseUpdater(
            new MaxMindGeoIpConfig(new Config($this->connection())),
            new SuccessfulGeoIpDownloadClient(),
            new SuccessfulGeoIpArchiveExtractor(),
            new ValidGeoIpReaderFactory(),
            $this->projectDir,
        );

        $result = $updater->update('scheduler');

        self::assertFalse($result->isSuccess());
        self::assertSame(GeoIpMessageCode::GEOIP_DOWNLOAD_MISSING_LICENSE_KEY, $result->firstIssue()?->code());
        self::assertSame(GeoIpMessageKey::GEOIP_DOWNLOAD_MISSING_LICENSE_KEY, $result->firstIssue()?->translationKey());
    }

    public function testItDownloadsValidatesAndReplacesDatabase(): void
    {
        $config = $this->configuredConfig('test-license');
        $updater = new MaxMindGeoIpDatabaseUpdater(
            $config,
            new SuccessfulGeoIpDownloadClient(),
            new SuccessfulGeoIpArchiveExtractor('new database'),
            new ValidGeoIpReaderFactory(),
            $this->projectDir,
        );
        $this->writeTestFile($this->projectDir, MaxMindGeoIpConfig::DEFAULT_DATABASE_PATH, 'old database');

        $result = $updater->update('admin_ui');

        self::assertTrue($result->isSuccess());
        self::assertSame(['database_path' => MaxMindGeoIpConfig::DEFAULT_DATABASE_PATH], $result->value());
        self::assertSame('new database', file_get_contents($this->projectDir.'/'.MaxMindGeoIpConfig::DEFAULT_DATABASE_PATH));
        self::assertSame(GeoIpMessageKey::GEOIP_DOWNLOAD_COMPLETED, $result->messages()[0]->translationKey());
    }

    public function testItForwardsDownloadFailureWithoutLeakingLicenseKey(): void
    {
        $config = $this->configuredConfig('sensitive-test-license');
        $updater = new MaxMindGeoIpDatabaseUpdater(
            $config,
            new FailingGeoIpDownloadClient(),
            new SuccessfulGeoIpArchiveExtractor(),
            new ValidGeoIpReaderFactory(),
            $this->projectDir,
        );

        $result = $updater->update('admin_ui');
        $encoded = json_encode($result->toArray(), JSON_THROW_ON_ERROR);

        self::assertFalse($result->isSuccess());
        self::assertSame(GeoIpMessageCode::GEOIP_DOWNLOAD_INVALID_LICENSE_KEY, $result->firstIssue()?->code());
        self::assertStringNotContainsString('sensitive-test-license', $encoded);
    }

    private function configuredConfig(string $licenseKey): MaxMindGeoIpConfig
    {
        $store = new Config($this->connection());
        $store->set(MaxMindGeoIpConfig::LICENSE_KEY_KEY, $licenseKey, ConfigValueType::String, sensitive: true);

        return new MaxMindGeoIpConfig($store);
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');

        return $connection;
    }
}

final readonly class SuccessfulGeoIpDownloadClient implements MaxMindGeoIpDownloadClientInterface
{
    public function download(string $url, string $targetPath): WorkflowResult
    {
        file_put_contents($targetPath, 'archive');

        return WorkflowResult::success();
    }
}

final readonly class FailingGeoIpDownloadClient implements MaxMindGeoIpDownloadClientInterface
{
    public function download(string $url, string $targetPath): WorkflowResult
    {
        return WorkflowResult::failed([
            Message::error(
                GeoIpMessageCode::GEOIP_DOWNLOAD_INVALID_LICENSE_KEY,
                GeoIpMessageKey::GEOIP_DOWNLOAD_INVALID_LICENSE_KEY,
                context: ['stage' => 'download', 'http_status' => 401],
            ),
        ], ['stage' => 'download', 'http_status' => 401]);
    }
}

final readonly class SuccessfulGeoIpArchiveExtractor implements MaxMindGeoIpArchiveExtractorInterface
{
    public function __construct(private string $databaseContents = 'database')
    {
    }

    public function extractDatabase(string $archivePath, string $workspaceDir): WorkflowResult
    {
        $databasePath = $workspaceDir.'/GeoLite2-City.mmdb';
        file_put_contents($databasePath, $this->databaseContents);

        return WorkflowResult::success(['database_path' => $databasePath]);
    }
}

final readonly class ValidGeoIpReaderFactory implements MaxMindGeoIpDatabaseReaderFactoryInterface
{
    public function open(string $databasePath, array $locales): MaxMindGeoIpDatabaseReaderInterface
    {
        return new ValidGeoIpReader();
    }
}

final readonly class ValidGeoIpReader implements MaxMindGeoIpDatabaseReaderInterface
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
