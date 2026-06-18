<?php

declare(strict_types=1);

namespace App\Tests\Security\AutoBan;

use App\Core\Config\Config;
use App\Core\Message\Message;
use App\Core\Message\MessageReporterInterface;
use App\Core\Log\DatabaseLogRetentionPolicy;
use App\Security\Abuse\SecuritySignalRecorder;
use App\Security\AutoBan\AutoBanPolicy;
use App\Security\AutoBan\AutoBanScoreCatalogue;
use App\Security\AutoBan\AutoBanSignalEvaluator;
use App\Security\AutoBan\AutoBanStore;
use App\Security\AutoBan\AutoBanSubject;
use App\Security\SecurityMessageCode;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class AutoBanSignalEvaluatorTest extends TestCase
{
    public function testFirstQualifyingSignalDoesNotCreateBanEvenWhenScoreReachesThreshold(): void
    {
        [$recorder, $store] = $this->stack();
        $visitor = new AutoBanSubject(AutoBanSubject::VISITOR, 'visitor-1');

        $this->recordProbe($recorder, $visitor, 'request-1');

        self::assertNull($store->active($visitor));

        $this->recordProbe($recorder, $visitor, 'request-2');

        self::assertNotNull($store->active($visitor));
    }

    public function testIpSubjectUsesLaxerThresholdMultiplier(): void
    {
        [$recorder, $store] = $this->stack();
        $ip = new AutoBanSubject(AutoBanSubject::IP, 'ip-bucket-1', true);

        for ($i = 1; $i <= 28; ++$i) {
            $this->recordError($recorder, $ip, 'request-'.$i);
        }

        self::assertNull($store->active($ip));

        $this->recordError($recorder, $ip, 'request-29');

        self::assertNotNull($store->active($ip));
    }

    public function testResetSignalInvalidatesEarlierScoreEvidence(): void
    {
        [$recorder, $store] = $this->stack();
        $visitor = new AutoBanSubject(AutoBanSubject::VISITOR, 'visitor-reset');

        $this->recordProbe($recorder, $visitor, 'request-1');
        $this->recordProbe($recorder, $visitor, 'request-2');
        self::assertNotNull($store->active($visitor));

        $store->reset($visitor->key());
        $recorder->record(
            'auto_ban',
            AutoBanScoreCatalogue::SIGNAL_RESET,
            $visitor->type(),
            $visitor->identifier(),
            requestId: 'request-reset',
        );

        $this->recordProbe($recorder, $visitor, 'request-3');

        self::assertNull($store->active($visitor));
    }

    public function testSameEvaluationPrefersVisitorBanOverIpBan(): void
    {
        [$recorder, $store] = $this->stack();
        $visitor = new AutoBanSubject(AutoBanSubject::VISITOR, 'visitor-shared');
        $ip = new AutoBanSubject(AutoBanSubject::IP, 'ip-shared', true);

        $this->recordProbe($recorder, $visitor, 'request-1');
        $this->recordProbe($recorder, $ip, 'request-1');
        $this->recordProbe($recorder, $visitor, 'request-2');
        $this->recordProbe($recorder, $ip, 'request-2');

        self::assertNotNull($store->active($visitor));
        self::assertNull($store->active($ip));
    }

    /**
     * @return array{0: SecuritySignalRecorder, 1: AutoBanStore, 2: Connection}
     */
    private function stack(): array
    {
        $connection = $this->connection();
        $clock = new MockClock('2026-06-18 12:00:00');
        $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
        $evaluator = new AutoBanSignalEvaluator(
            $connection,
            new DatabaseLogRetentionPolicy($connection),
            new AutoBanPolicy(new Config($connection)),
            new AutoBanScoreCatalogue(),
            $store,
            clock: $clock,
        );
        $recorder = new SecuritySignalRecorder(
            $connection,
            new DatabaseLogRetentionPolicy($connection),
            clock: $clock,
            autoBanSignals: $evaluator,
        );

        return [$recorder, $store, $connection];
    }

    private function recordProbe(SecuritySignalRecorder $recorder, AutoBanSubject $subject, string $requestId): void
    {
        $recorder->record(
            'probe',
            AutoBanScoreCatalogue::SIGNAL_SUSPICIOUS_PROBE,
            $subject->type(),
            $subject->identifier(),
            ipDerived: $subject->ipDerived(),
            severity: 'WARNING',
            confidence: 95,
            requestId: $requestId,
            visitorId: 'visitor-context',
            httpStatus: 400,
        );
    }

    private function recordError(SecuritySignalRecorder $recorder, AutoBanSubject $subject, string $requestId): void
    {
        $recorder->record(
            'http_error',
            AutoBanScoreCatalogue::SIGNAL_ERROR_HIT,
            $subject->type(),
            $subject->identifier(),
            ipDerived: $subject->ipDerived(),
            severity: 'NOTICE',
            confidence: 40,
            requestId: $requestId,
            visitorId: 'visitor-context',
            httpStatus: 404,
        );
    }

    public function testInvalidActiveBanPayloadFailsOpenWithMessage(): void
    {
        $clock = new MockClock('2026-06-18 12:00:00');
        $cache = new ArrayAdapter();
        $messages = new RecordingAutoBanMessageReporter();
        $store = new AutoBanStore($cache, new LockFactory(new InMemoryStore()), $messages, $clock);
        $subject = new AutoBanSubject(AutoBanSubject::VISITOR, 'visitor-invalid-payload');
        $item = $cache->getItem('security.auto_ban.active.'.$subject->key());
        $item->set([
            'key' => $subject->key(),
            'subject_type' => $subject->type(),
            'subject_identifier' => $subject->identifier(),
            'created_at' => 'not-a-date',
            'expires_at' => '2026-06-18 13:00:00',
            'ttl_seconds' => 3600,
        ]);
        $cache->save($item);

        self::assertNull($store->active($subject));
        self::assertSame(SecurityMessageCode::AUTO_BAN_PAYLOAD_INVALID, $messages->records[0]['message']->code());
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');
        $connection->executeStatement('CREATE TABLE security_signal_event (uid VARCHAR(36) PRIMARY KEY NOT NULL, occurred_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, signal_type VARCHAR(80) NOT NULL, reason_code VARCHAR(120) NOT NULL, severity VARCHAR(16) NOT NULL, confidence INTEGER NOT NULL, subject_type VARCHAR(40) NOT NULL, subject_identifier VARCHAR(190) NOT NULL, ip_derived BOOLEAN NOT NULL, request_family VARCHAR(40) NOT NULL, request_intent VARCHAR(80) NOT NULL, request_id VARCHAR(64) NOT NULL, visitor_id VARCHAR(64) NOT NULL, path VARCHAR(1024) NOT NULL, route VARCHAR(190) NOT NULL, http_status INTEGER DEFAULT NULL, context CLOB NOT NULL)');
        $connection->executeStatement('CREATE INDEX idx_security_signal_subject_at ON security_signal_event (subject_type, subject_identifier, occurred_at)');

        return $connection;
    }
}

final class RecordingAutoBanMessageReporter implements MessageReporterInterface
{
    /**
     * @var list<array{message: Message, context: array<string, mixed>}>
     */
    public array $records = [];

    public function report(Message $message, array $context = []): Message
    {
        $this->records[] = ['message' => $message, 'context' => $context];

        return $message;
    }

    public function reportBatch(iterable $records): array
    {
        $messages = [];
        foreach ($records as $record) {
            $message = $record['message'];
            if (!$message instanceof Message) {
                continue;
            }

            $messages[] = $this->report($message, $record['context'] ?? []);
        }

        return $messages;
    }
}
