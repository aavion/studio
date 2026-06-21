<?php

declare(strict_types=1);

namespace App\Tests\Core\Message;

use App\Core\Log\MessageLoggerInterface;
use App\Core\Manifest\ManifestMessageCode;
use App\Core\Manifest\ManifestMessageKey;
use App\Core\Message\Message;
use App\Core\Message\MessageReporter;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use PHPUnit\Framework\TestCase;

final class MessageReporterTest extends TestCase
{
    public function testItReturnsReportedMessagesUnchanged(): void
    {
        $logger = new RecordingMessageLogger();
        $reporter = new MessageReporter($logger);
        $message = Message::info(ExtensionMessageCode::EXTENSION_DISCOVERY_COMPLETED, ExtensionMessageKey::EXTENSION_DISCOVERY_COMPLETED);

        self::assertSame($message, $reporter->report($message, ['operation' => 'extension.discovery']));
        self::assertCount(1, $logger->records);
        self::assertSame($message, $logger->records[0]['message']);
        self::assertSame(['operation' => 'extension.discovery'], $logger->records[0]['context']);
    }

    public function testItReturnsReportedBatchesUnchanged(): void
    {
        $logger = new RecordingMessageLogger();
        $reporter = new MessageReporter($logger);
        $first = Message::debug(ManifestMessageCode::MANIFEST_PARSED, ManifestMessageKey::MANIFEST_PARSED);
        $second = Message::info(ExtensionMessageCode::EXTENSION_DISCOVERY_COMPLETED, ExtensionMessageKey::EXTENSION_DISCOVERY_COMPLETED);

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
