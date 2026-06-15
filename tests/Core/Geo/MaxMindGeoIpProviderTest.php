<?php

declare(strict_types=1);

namespace App\Tests\Core\Geo;

use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Geo\MaxMindGeoIpConfig;
use App\Core\Geo\MaxMindGeoIpDatabaseReaderFactoryInterface;
use App\Core\Geo\MaxMindGeoIpDatabaseReaderInterface;
use App\Core\Geo\MaxMindGeoIpProvider;
use App\Tests\Support\FilesystemTestHelper;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use GeoIp2\Model\City;
use MaxMind\Db\Reader\Metadata;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MaxMindGeoIpProviderTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = $this->createTemporaryDirectory('maxmind-geoip-provider');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testItStaysDisabledByDefault(): void
    {
        $factory = new RecordingMaxMindReaderFactory($this->reader());
        $provider = new MaxMindGeoIpProvider(new MaxMindGeoIpConfig(new Config($this->connection())), $factory, $this->projectDir);

        self::assertSame('maxmind', $provider->key());
        self::assertSame('disabled', $provider->status()->status);
        self::assertSame([
            'city' => 'n/a',
            'state' => 'n/a',
            'country' => 'n/a',
            'continent' => 'n/a',
        ], $provider->resolve('8.8.8.8')->toArray());
        self::assertSame(0, $factory->openCount);
    }

    public function testItReportsMissingConfiguredDatabaseAsUnconfigured(): void
    {
        $provider = new MaxMindGeoIpProvider($this->enabledConfig(), new RecordingMaxMindReaderFactory($this->reader()), $this->projectDir);

        self::assertSame('unconfigured', $provider->status()->status);
        self::assertSame('database_missing', $provider->status()->failureCode);
    }

    public function testItReadsLocalDatabaseThroughGeoIp2Boundary(): void
    {
        $this->writeTestFile($this->projectDir, MaxMindGeoIpConfig::DEFAULT_DATABASE_PATH, 'fake mmdb');
        $reader = $this->reader();
        $factory = new RecordingMaxMindReaderFactory($reader);
        $provider = new MaxMindGeoIpProvider($this->enabledConfig(), $factory, $this->projectDir);

        self::assertSame('ready', $provider->status()->status);
        self::assertSame('GeoLite2-City', $provider->status()->databaseEdition);
        self::assertSame('2026-06-15', $provider->status()->databaseBuildDate);
        self::assertSame([
            'city' => 'Berlin',
            'state' => 'Berlin',
            'country' => 'Germany',
            'continent' => 'Europe',
        ], $provider->resolve('8.8.8.8')->toArray());
        self::assertSame(1, $factory->openCount);
        self::assertSame($this->projectDir.'/'.MaxMindGeoIpConfig::DEFAULT_DATABASE_PATH, $factory->lastDatabasePath);
        self::assertSame(['en'], $factory->lastLocales);
        self::assertSame(1, $reader->cityLookupCount);
    }

    public function testItDoesNotLookupPrivateOrInvalidIpAddresses(): void
    {
        $this->writeTestFile($this->projectDir, MaxMindGeoIpConfig::DEFAULT_DATABASE_PATH, 'fake mmdb');
        $reader = $this->reader();
        $provider = new MaxMindGeoIpProvider($this->enabledConfig(), new RecordingMaxMindReaderFactory($reader), $this->projectDir);

        self::assertSame('ready', $provider->status()->status);
        self::assertSame([
            'city' => 'n/a',
            'state' => 'n/a',
            'country' => 'n/a',
            'continent' => 'n/a',
        ], $provider->resolve('127.0.0.1')->toArray());
        self::assertSame([
            'city' => 'n/a',
            'state' => 'n/a',
            'country' => 'n/a',
            'continent' => 'n/a',
        ], $provider->resolve('not-an-ip')->toArray());
        self::assertSame(0, $reader->cityLookupCount);
    }

    public function testItReportsUnreadableDatabaseWithoutLeakingPath(): void
    {
        $this->writeTestFile($this->projectDir, MaxMindGeoIpConfig::DEFAULT_DATABASE_PATH, 'fake mmdb');
        $provider = new MaxMindGeoIpProvider($this->enabledConfig(), new ThrowingMaxMindReaderFactory(), $this->projectDir);

        $status = $provider->status();

        self::assertSame('unavailable', $status->status);
        self::assertSame('database_unreadable', $status->failureCode);
        self::assertStringNotContainsString($this->projectDir, json_encode($status->toSafeArray(), JSON_THROW_ON_ERROR));
    }

    private function enabledConfig(): MaxMindGeoIpConfig
    {
        $store = new Config($this->connection());
        $store->set(MaxMindGeoIpConfig::ENABLED_KEY, true, ConfigValueType::Boolean);

        return new MaxMindGeoIpConfig($store);
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');

        return $connection;
    }

    private function reader(): RecordingMaxMindReader
    {
        return new RecordingMaxMindReader(new City([
            'city' => ['names' => ['en' => 'Berlin']],
            'subdivisions' => [
                ['names' => ['en' => 'Berlin'], 'iso_code' => 'BE'],
            ],
            'country' => ['names' => ['en' => 'Germany'], 'iso_code' => 'DE'],
            'continent' => ['names' => ['en' => 'Europe'], 'code' => 'EU'],
        ]));
    }
}

final class RecordingMaxMindReaderFactory implements MaxMindGeoIpDatabaseReaderFactoryInterface
{
    public int $openCount = 0;
    public ?string $lastDatabasePath = null;
    /** @var list<string> */
    public array $lastLocales = [];

    public function __construct(private readonly RecordingMaxMindReader $reader)
    {
    }

    public function open(string $databasePath, array $locales): MaxMindGeoIpDatabaseReaderInterface
    {
        ++$this->openCount;
        $this->lastDatabasePath = $databasePath;
        $this->lastLocales = $locales;

        return $this->reader;
    }
}

final class ThrowingMaxMindReaderFactory implements MaxMindGeoIpDatabaseReaderFactoryInterface
{
    public function open(string $databasePath, array $locales): MaxMindGeoIpDatabaseReaderInterface
    {
        throw new RuntimeException('Cannot open local test database.');
    }
}

final class RecordingMaxMindReader implements MaxMindGeoIpDatabaseReaderInterface
{
    public int $cityLookupCount = 0;

    public function __construct(private readonly City $city)
    {
    }

    public function city(string $ipAddress): City
    {
        ++$this->cityLookupCount;

        return $this->city;
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
