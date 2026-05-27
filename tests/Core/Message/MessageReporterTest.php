<?php

declare(strict_types=1);

namespace App\Tests\Core\Message;

use App\Core\Log\MessageLoggerInterface;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageReporter;
use PHPUnit\Framework\TestCase;

final class MessageReporterTest extends TestCase
{
    public function testItReturnsReportedMessagesUnchanged(): void
    {
        $logger = new RecordingMessageLogger();
        $reporter = new MessageReporter($logger);
        $message = Message::info(MessageCode::PACKAGE_DISCOVERY_COMPLETED, MessageKey::PACKAGE_DISCOVERY_COMPLETED);

        self::assertSame($message, $reporter->report($message, ['operation' => 'package.discovery']));
        self::assertCount(1, $logger->records);
        self::assertSame($message, $logger->records[0]['message']);
        self::assertSame(['operation' => 'package.discovery'], $logger->records[0]['context']);
    }

    public function testItReturnsReportedBatchesUnchanged(): void
    {
        $logger = new RecordingMessageLogger();
        $reporter = new MessageReporter($logger);
        $first = Message::debug(MessageCode::MANIFEST_PARSED, MessageKey::MANIFEST_PARSED);
        $second = Message::info(MessageCode::PACKAGE_DISCOVERY_COMPLETED, MessageKey::PACKAGE_DISCOVERY_COMPLETED);

        $messages = $reporter->reportBatch([
            [
                'message' => $first,
                'context' => [
                    'kind' => 'debug',
                ],
            ],
            [
                'message' => $second,
                'context' => [
                    'kind' => 'info',
                ],
            ],
        ]);

        self::assertSame([$first, $second], $messages);
        self::assertSame([$first, $second], array_column($logger->records, 'message'));
        self::assertSame(['kind' => 'debug'], $logger->records[0]['context']);
        self::assertSame(['kind' => 'info'], $logger->records[1]['context']);
    }
}

final class RecordingMessageLogger implements MessageLoggerInterface
{
    /**
     * @var list<array{message: Message, context: array<string, mixed>}>
     */
    public array $records = [];

    public function log(Message $message, array $context = []): void
    {
        $this->records[] = [
            'message' => $message,
            'context' => $context,
        ];
    }

    public function logBatch(iterable $records): void
    {
        foreach ($records as $record) {
            $this->log($record['message'], $record['context'] ?? []);
        }
    }
}
