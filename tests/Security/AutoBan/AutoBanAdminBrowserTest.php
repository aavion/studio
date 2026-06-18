<?php

declare(strict_types=1);

namespace App\Tests\Security\AutoBan;

use App\Security\AutoBan\AutoBanAdminBrowser;
use App\Security\AutoBan\AutoBanScoreCatalogue;
use App\Security\AutoBan\AutoBanStore;
use App\Security\AutoBan\AutoBanSubject;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class AutoBanAdminBrowserTest extends TestCase
{
    public function testDetailKeepsNewestSignalsVisibleBeforeRetainedHistory(): void
    {
        $connection = $this->connection();
        $clock = new MockClock('2026-06-18 13:00:00');
        $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
        $subject = new AutoBanSubject(AutoBanSubject::VISITOR, 'visitor-detail-history');
        $ban = $store->ban($subject, 3600);
        self::assertNotNull($ban);

        for ($i = 0; $i < 105; ++$i) {
            $this->insertSignal($connection, $subject, sprintf('old-%03d', $i), sprintf('2026-06-18 11:%02d:%02d', intdiv($i, 60), $i % 60));
        }
        $this->insertSignal($connection, $subject, 'reset', '2026-06-18 12:00:00', AutoBanScoreCatalogue::SIGNAL_RESET);
        $this->insertSignal($connection, $subject, 'new-001', '2026-06-18 12:01:00');
        $this->insertSignal($connection, $subject, 'new-002', '2026-06-18 12:02:00');

        $detail = (new AutoBanAdminBrowser($store, $connection, clock: $clock))->detail($ban->key());
        self::assertNotNull($detail);

        self::assertCount(100, $detail['signals']);
        self::assertSame('new-002', $detail['signals'][0]['uid']);
        self::assertSame('new-001', $detail['signals'][1]['uid']);
        self::assertContains('old-104', array_column($detail['signals'], 'uid'));
    }

    public function testDetailShowsGeoFromLatestBanTriggerRequest(): void
    {
        $connection = $this->connection();
        $this->createAccessLogTable($connection);
        $clock = new MockClock('2026-06-18 13:00:00');
        $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
        $subject = new AutoBanSubject(AutoBanSubject::IP, 'ip-bucket-detail-geo', true);
        $ban = $store->ban($subject, 3600);
        self::assertNotNull($ban);

        $this->insertSignal($connection, $subject, 'trigger-old', '2026-06-18 12:01:00', AutoBanScoreCatalogue::SIGNAL_TRIGGERED);
        $this->insertSignal($connection, $subject, 'trigger-new', '2026-06-18 12:02:00', AutoBanScoreCatalogue::SIGNAL_TRIGGERED);
        $connection->insert('access_log_entry', [
            'uid' => 'access-old',
            'occurred_at' => '2026-06-18 12:01:00',
            'request_id' => 'request-trigger-old',
            'country' => 'DE',
            'continent' => 'EU',
        ]);
        $connection->insert('access_log_entry', [
            'uid' => 'access-new',
            'occurred_at' => '2026-06-18 12:02:00',
            'request_id' => 'request-trigger-new',
            'country' => 'NL',
            'continent' => 'EU',
        ]);

        $detail = (new AutoBanAdminBrowser($store, $connection, clock: $clock))->detail($ban->key());
        self::assertNotNull($detail);

        self::assertSame([
            'request_id' => 'request-trigger-new',
            'country' => 'NL',
            'continent' => 'EU',
        ], $detail['trigger_geo']);
    }

    public function testDetailUsesSafeGeoPlaceholdersWhenAccessLogIsUnavailable(): void
    {
        $connection = $this->connection();
        $clock = new MockClock('2026-06-18 13:00:00');
        $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
        $subject = new AutoBanSubject(AutoBanSubject::VISITOR, 'visitor-detail-no-geo');
        $ban = $store->ban($subject, 3600);
        self::assertNotNull($ban);
        $this->insertSignal($connection, $subject, 'trigger-new', '2026-06-18 12:02:00', AutoBanScoreCatalogue::SIGNAL_TRIGGERED);

        $detail = (new AutoBanAdminBrowser($store, $connection, clock: $clock))->detail($ban->key());
        self::assertNotNull($detail);

        self::assertSame([
            'request_id' => 'n/a',
            'country' => 'n/a',
            'continent' => 'n/a',
        ], $detail['trigger_geo']);
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE security_signal_event (uid VARCHAR(36) PRIMARY KEY NOT NULL, occurred_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, signal_type VARCHAR(80) NOT NULL, reason_code VARCHAR(120) NOT NULL, severity VARCHAR(16) NOT NULL, confidence INTEGER NOT NULL, subject_type VARCHAR(40) NOT NULL, subject_identifier VARCHAR(190) NOT NULL, ip_derived BOOLEAN NOT NULL, request_family VARCHAR(40) NOT NULL, request_intent VARCHAR(80) NOT NULL, request_id VARCHAR(64) NOT NULL, visitor_id VARCHAR(64) NOT NULL, path VARCHAR(1024) NOT NULL, route VARCHAR(190) NOT NULL, http_status INTEGER DEFAULT NULL, context CLOB NOT NULL)');

        return $connection;
    }

    private function createAccessLogTable(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE access_log_entry (uid VARCHAR(36) PRIMARY KEY NOT NULL, occurred_at DATETIME NOT NULL, request_id VARCHAR(64) NOT NULL, country VARCHAR(80) NOT NULL, continent VARCHAR(80) NOT NULL)');
    }

    private function insertSignal(
        Connection $connection,
        AutoBanSubject $subject,
        string $uid,
        string $occurredAt,
        string $reasonCode = AutoBanScoreCatalogue::SIGNAL_ERROR_HIT,
    ): void {
        $connection->insert('security_signal_event', [
            'uid' => $uid,
            'occurred_at' => $occurredAt,
            'expires_at' => '2026-06-25 00:00:00',
            'signal_type' => 'http_error',
            'reason_code' => $reasonCode,
            'severity' => 'NOTICE',
            'confidence' => 40,
            'subject_type' => $subject->type(),
            'subject_identifier' => $subject->identifier(),
            'ip_derived' => 0,
            'request_family' => 'frontend',
            'request_intent' => 'navigation',
            'request_id' => 'request-'.$uid,
            'visitor_id' => $subject->identifier(),
            'path' => '/missing',
            'route' => 'n/a',
            'http_status' => 404,
            'context' => '{}',
        ]);
    }
}
