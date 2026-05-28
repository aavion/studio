<?php

declare(strict_types=1);

namespace App\Tests\Core\Log;

use App\Core\Log\OperationLogger;
use App\Core\Log\MessageLoggerInterface;
use App\Core\Message\Message;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageReporter;
use PHPUnit\Framework\TestCase;

final class OperationLoggerTest extends TestCase
{
    public function testItWritesFinishedOperationSummaries(): void
    {
        $logger = new RecordingOperationMessageLogger();

        (new OperationLogger(new MessageReporter($logger)))->logFinished([
            'operation_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'operation' => 'backend.cache_clear',
            'label' => 'Cache clear',
            'status' => 'success',
            'started_at' => '2026-05-27T10:00:00+00:00',
            'finished_at' => '2026-05-27T10:00:01+00:00',
            'entries' => [['name' => 'cache:clear']],
            'result' => [
                'status' => 'success',
                'issues' => [],
                'messages' => [['code' => 'success']],
            ],
        ]);

        $records = $logger->records;

        self::assertCount(1, $records);
        self::assertSame(MessageKey::OPERATION_FINISHED, $records[0]['message']->translationKey());
        self::assertSame('live_operation.summary', $records[0]['context']['operation']);
        self::assertSame('backend.cache_clear', $records[0]['message']->context()['operation']);
        self::assertSame('success', $records[0]['message']->context()['status']);
        self::assertSame(1, $records[0]['message']->context()['entry_count']);
        self::assertSame(0, $records[0]['message']->context()['issue_count']);
        self::assertSame(1, $records[0]['message']->context()['message_count']);
        self::assertFalse($records[0]['message']->context()['can_continue']);
    }

    public function testItUsesReviewAndFailureLevels(): void
    {
        $messageLogger = new RecordingOperationMessageLogger();
        $logger = new OperationLogger(new MessageReporter($messageLogger));

        $logger->logFinished([
            'operation' => 'package.install.verify',
            'status' => 'requires_review',
            'result' => [
                'status' => 'requires_review',
                'context' => ['live_operation_continuation' => ['operation' => 'package.install.apply']],
            ],
        ]);
        $logger->logFinished([
            'operation' => 'package.install.apply',
            'status' => 'failed',
            'result' => ['status' => 'failed', 'issues' => [['code' => 'failed']]],
        ]);

        $records = $messageLogger->records;

        self::assertSame(MessageKey::OPERATION_REQUIRES_REVIEW, $records[0]['message']->translationKey());
        self::assertTrue($records[0]['message']->context()['can_continue']);
        self::assertSame(MessageKey::OPERATION_FAILED, $records[1]['message']->translationKey());
    }
}

final class RecordingOperationMessageLogger implements MessageLoggerInterface
{
    /**
     * @var list<array{message: Message, context: array<string, mixed>}>
     */
    public array $records = [];

    public function log(Message $message, array $context = []): void
    {
        $this->records[] = ['message' => $message, 'context' => $context];
    }

    public function logBatch(iterable $records): void
    {
        foreach ($records as $record) {
            $this->log($record['message'], $record['context'] ?? []);
        }
    }
}
