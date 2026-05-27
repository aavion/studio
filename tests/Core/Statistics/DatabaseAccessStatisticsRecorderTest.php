<?php

declare(strict_types=1);

namespace App\Tests\Core\Statistics;

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
                visitor_id VARCHAR(64) NOT NULL,
                method VARCHAR(16) NOT NULL,
                path VARCHAR(1024) NOT NULL,
                route VARCHAR(190) NOT NULL,
                http_status INTEGER NOT NULL,
                browser_family VARCHAR(40) NOT NULL,
                device_type VARCHAR(40) NOT NULL,
                is_bot BOOLEAN NOT NULL,
                city VARCHAR(80) NOT NULL,
                state VARCHAR(80) NOT NULL,
                country VARCHAR(80) NOT NULL,
                continent VARCHAR(80) NOT NULL,
                metadata CLOB NOT NULL
            )
            SQL);
    }

    public function testItRecordsAnonymizedRequestEvents(): void
    {
        $request = Request::create('/docs?token=secret', 'GET', server: [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'Studio Browser/1.0',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.23, 203.0.113.10',
        ]);
        $request->attributes->set('_route', 'content_view');

        $visitorIdGenerator = new VisitorIdGenerator('test-secret');

        (new DatabaseAccessStatisticsRecorder($this->connection, $visitorIdGenerator, new UserAgentClassifier()))->record($request, new Response('', 404));

        $row = $this->connection->fetchAssociative('SELECT * FROM access_statistic_event');

        self::assertIsArray($row);
        self::assertSame($visitorIdGenerator->generate($request), $row['visitor_id']);
        self::assertSame('GET', $row['method']);
        self::assertSame('/docs', $row['path']);
        self::assertSame('content_view', $row['route']);
        self::assertSame(404, (int) $row['http_status']);
        self::assertSame('other', $row['browser_family']);
        self::assertSame('desktop', $row['device_type']);
        self::assertFalse((bool) $row['is_bot']);
        self::assertSame(['query_present' => true], json_decode((string) $row['metadata'], true, flags: JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('203.0.113', implode(' ', array_map('strval', $row)));
        self::assertStringNotContainsString('198.51.100', implode(' ', array_map('strval', $row)));
        self::assertStringNotContainsString('Studio Browser', implode(' ', array_map('strval', $row)));
        self::assertStringNotContainsString('secret', implode(' ', array_map('strval', $row)));
    }

    public function testItDoesNotThrowWhenStatisticsTableIsUnavailable(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $request = Request::create('/docs', 'GET');

        (new DatabaseAccessStatisticsRecorder($connection, new VisitorIdGenerator('test-secret'), new UserAgentClassifier()))->record($request, new Response('', 200));

        self::assertTrue(true);
    }
}
