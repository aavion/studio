<?php

declare(strict_types=1);

namespace App\Tests\Core\Statistics;

use App\Core\Log\AccessRequestMetadata;
use App\Core\Geo\NullGeoIpResolver;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Statistics\AccessStatisticsPolicy;
use App\Core\Statistics\DatabaseAccessStatisticsRecorder;
use App\Core\Statistics\UserAgentClassifier;
use App\Core\Statistics\VisitorIdGenerator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class DatabaseAccessStatisticsRecorderTest extends TestCase
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
        $this->connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL, modified_at DATETIME NOT NULL, modified_by VARCHAR(180) DEFAULT NULL)');
    }

    public function testItRecordsAnonymizedRequestEvents(): void
    {
        $request = Request::create('/docs?token=secret', 'GET', server: [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'Studio Browser/1.0',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.23, 203.0.113.10',
            'HTTP_REFERER' => 'https://example.org/source?token=secret',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
            'CONTENT_TYPE' => 'application/json',
        ]);
        $request->attributes->set('_route', 'content_view');

        $visitorIdGenerator = new VisitorIdGenerator('test-secret');
        $metadata = new AccessRequestMetadata();
        $metadata->markStarted($request);

        (new DatabaseAccessStatisticsRecorder($this->connection, $visitorIdGenerator, new UserAgentClassifier(), $metadata, new NullGeoIpResolver()))->record(
            $request,
            new Response('Missing', 404, ['Content-Type' => 'text/html']),
        );

        $row = $this->connection->fetchAssociative('SELECT * FROM access_statistic_event');

        self::assertIsArray($row);
        self::assertSame($visitorIdGenerator->generate($request), $row['visitor_id']);
        self::assertSame($metadata->requestId($request), $row['request_id']);
        self::assertSame('GET', $row['method']);
        self::assertSame('/docs', $row['path']);
        self::assertSame('/docs', $row['requested_path']);
        self::assertSame('content_view', $row['route']);
        self::assertSame('content_view', $row['resolved_route']);
        self::assertSame('public', $row['surface']);
        self::assertSame(404, (int) $row['http_status']);
        self::assertIsNumeric($row['duration_ms']);
        self::assertSame('other', $row['browser_family']);
        self::assertSame('desktop', $row['device_type']);
        self::assertFalse((bool) $row['is_bot']);
        self::assertFalse((bool) $row['do_not_track']);
        self::assertSame('example.org', $row['referrer_host']);
        self::assertSame('en-us', $row['preferred_language']);
        self::assertSame('application/json', $row['request_content_type']);
        self::assertSame('text/html', $row['response_content_type']);
        self::assertSame(7, (int) $row['response_size']);
        self::assertSame(['query_present' => true], json_decode((string) $row['metadata'], true, flags: JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('203.0.113', implode(' ', array_map('strval', $row)));
        self::assertStringNotContainsString('198.51.100', implode(' ', array_map('strval', $row)));
        self::assertStringNotContainsString('Studio Browser', implode(' ', array_map('strval', $row)));
        self::assertStringNotContainsString('secret', implode(' ', array_map('strval', $row)));
    }

    public function testItRedactsTokenizedPathSegments(): void
    {
        $request = Request::create('/user/invitation/test-token', 'GET');
        $request->attributes->set('_route', 'user_invitation_accept');
        $request->attributes->set('token', 'test-token');

        (new DatabaseAccessStatisticsRecorder(
            $this->connection,
            new VisitorIdGenerator('test-secret'),
            new UserAgentClassifier(),
            new AccessRequestMetadata(),
            new NullGeoIpResolver(),
        ))->record($request, new Response('', 200));

        $row = $this->connection->fetchAssociative('SELECT path, requested_path FROM access_statistic_event');

        self::assertIsArray($row);
        self::assertSame('/user/invitation/[redacted]', $row['path']);
        self::assertSame('/user/invitation/[redacted]', $row['requested_path']);
        self::assertStringNotContainsString('test-token', implode(' ', array_map('strval', $row)));
    }

    public function testItDoesNotThrowWhenStatisticsTableIsUnavailable(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $request = Request::create('/docs', 'GET');

        (new DatabaseAccessStatisticsRecorder($connection, new VisitorIdGenerator('test-secret'), new UserAgentClassifier(), new AccessRequestMetadata(), new NullGeoIpResolver()))->record($request, new Response('', 200));

        self::assertTrue(true);
    }

    public function testItStopsRecordingWhenStatisticsAreDisabled(): void
    {
        $config = new Config($this->connection);
        $config->set(AccessStatisticsPolicy::ENABLED_KEY, false, ConfigValueType::Boolean);

        (new DatabaseAccessStatisticsRecorder(
            $this->connection,
            new VisitorIdGenerator('test-secret'),
            new UserAgentClassifier(),
            new AccessRequestMetadata(),
            new NullGeoIpResolver(),
            new AccessStatisticsPolicy($config),
        ))->record(Request::create('/docs', 'GET'), new Response('', 200));

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM access_statistic_event'));
    }

    public function testItSkipsRecordingWhenDoNotTrackIsEnabled(): void
    {
        $config = new Config($this->connection);
        $config->set(AccessStatisticsPolicy::ENABLED_KEY, true, ConfigValueType::Boolean);
        $config->set(AccessStatisticsPolicy::RESPECT_DO_NOT_TRACK_KEY, true, ConfigValueType::Boolean);
        $request = Request::create('/docs', 'GET', server: ['HTTP_DNT' => '1']);

        (new DatabaseAccessStatisticsRecorder(
            $this->connection,
            new VisitorIdGenerator('test-secret'),
            new UserAgentClassifier(),
            new AccessRequestMetadata(),
            new NullGeoIpResolver(),
            new AccessStatisticsPolicy($config),
        ))->record($request, new Response('', 200));

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM access_statistic_event'));
    }

    public function testItStoresDoNotTrackWhenHeaderIsNotRespected(): void
    {
        $config = new Config($this->connection);
        $config->set(AccessStatisticsPolicy::RESPECT_DO_NOT_TRACK_KEY, false, ConfigValueType::Boolean);
        $request = Request::create('/docs', 'GET', server: ['HTTP_DNT' => '1']);

        (new DatabaseAccessStatisticsRecorder(
            $this->connection,
            new VisitorIdGenerator('test-secret'),
            new UserAgentClassifier(),
            new AccessRequestMetadata(),
            new NullGeoIpResolver(),
            new AccessStatisticsPolicy($config),
        ))->record($request, new Response('', 200));

        self::assertTrue((bool) $this->connection->fetchOne('SELECT do_not_track FROM access_statistic_event'));
    }

    public function testItDeletesStatisticEventsOlderThanThreeMonths(): void
    {
        $this->connection->insert('access_statistic_event', [
            'uid' => '00000000-0000-7000-8000-000000000999',
            'occurred_at' => (new \DateTimeImmutable('-4 months'))->format('Y-m-d H:i:s'),
            'request_id' => 'old-request',
            'visitor_id' => 'old-visitor',
            'method' => 'GET',
            'path' => '/old',
            'requested_path' => '/old',
            'route' => 'old_route',
            'resolved_route' => 'old_route',
            'surface' => 'public',
            'http_status' => 200,
            'duration_ms' => 1,
            'browser_family' => 'other',
            'device_type' => 'desktop',
            'is_bot' => false,
            'do_not_track' => false,
            'referrer_host' => 'n/a',
            'preferred_language' => 'n/a',
            'request_content_type' => 'n/a',
            'response_content_type' => 'text/html',
            'response_size' => 1,
            'city' => 'n/a',
            'state' => 'n/a',
            'country' => 'n/a',
            'continent' => 'n/a',
            'metadata' => '{}',
        ]);

        (new DatabaseAccessStatisticsRecorder($this->connection, new VisitorIdGenerator('test-secret'), new UserAgentClassifier(), new AccessRequestMetadata(), new NullGeoIpResolver()))->record(
            Request::create('/docs', 'GET'),
            new Response('', 200),
        );

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM access_statistic_event WHERE request_id = ?', ['old-request']));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM access_statistic_event WHERE path = ?', ['/docs']));
    }
}
