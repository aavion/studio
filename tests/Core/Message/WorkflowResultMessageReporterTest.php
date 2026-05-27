<?php

declare(strict_types=1);

namespace App\Tests\Core\Message;

use App\Core\ActionLog\ActionLog;
use App\Core\ActionLog\ActionLogEntry;
use App\Core\ActionLog\ActionLogStatus;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageReporterInterface;
use App\Core\Message\WorkflowResultMessageReporter;
use App\Core\Workflow\WorkflowResult;
use PHPUnit\Framework\TestCase;

final class WorkflowResultMessageReporterTest extends TestCase
{
    public function testItReturnsTheOriginalResultAfterLogging(): void
    {
        $messageReporter = new RecordingMessageReporter();
        $reporter = new WorkflowResultMessageReporter($messageReporter);
        $result = WorkflowResult::success(messages: [
            Message::info(MessageCode::PACKAGE_DISCOVERY_COMPLETED, MessageKey::PACKAGE_DISCOVERY_COMPLETED, [
                '%count%' => 1,
            ]),
        ]);

        self::assertSame($result, $reporter->report($result, ['operation' => 'test']));
        self::assertCount(1, $messageReporter->records);
        self::assertSame(MessageKey::PACKAGE_DISCOVERY_COMPLETED, $messageReporter->records[0]['message']->translationKey());
        self::assertSame(['operation' => 'test'], $messageReporter->records[0]['context']['operation_context']);
        self::assertSame('message', $messageReporter->records[0]['context']['kind']);
    }

    public function testItDoesNotLogTheSameResultObjectTwice(): void
    {
        $messageReporter = new RecordingMessageReporter();
        $reporter = new WorkflowResultMessageReporter($messageReporter);
        $result = WorkflowResult::success(messages: [
            Message::info(MessageCode::PACKAGE_DISCOVERY_COMPLETED, MessageKey::PACKAGE_DISCOVERY_COMPLETED, [
                '%count%' => 1,
            ]),
        ]);

        $reporter->report($result, ['operation' => 'inner']);
        $reporter->report($result, ['operation' => 'outer']);

        self::assertCount(1, $messageReporter->records);
        self::assertSame(['operation' => 'inner'], $messageReporter->records[0]['context']['operation_context']);
    }

    public function testItReportsIssuesAndActionLogMessagesAsMessages(): void
    {
        $messageReporter = new RecordingMessageReporter();
        $reporter = new WorkflowResultMessageReporter($messageReporter);
        $issue = Message::create(MessageCode::PROCESS_COMMAND_FAILED, MessageKey::PROCESS_COMMAND_FAILED);
        $message = Message::info(MessageCode::SETUP_LANGUAGE_SELECTED, MessageKey::SETUP_LANGUAGE_SELECTED, [
            '%language%' => 'en',
        ]);
        $log = ActionLog::create()->add(
            ActionLogEntry::pending('select_language', ['language' => 'en'])
                ->start()
                ->finish(ActionLogStatus::Success, messages: [$message]),
        );

        $reporter->report(WorkflowResult::requiresReview($log, [$issue]), ['operation' => 'setup.run']);

        self::assertCount(2, $messageReporter->records);
        self::assertSame(MessageKey::PROCESS_COMMAND_FAILED, $messageReporter->records[0]['message']->translationKey());
        self::assertSame('issue', $messageReporter->records[0]['context']['kind']);
        self::assertSame(MessageKey::SETUP_LANGUAGE_SELECTED, $messageReporter->records[1]['message']->translationKey());
        self::assertSame('action_log_message', $messageReporter->records[1]['context']['kind']);
        self::assertSame('select_language', $messageReporter->records[1]['context']['action_log_entry']['name']);
    }
}

final class RecordingMessageReporter implements MessageReporterInterface
{
    /**
     * @var list<array{message: Message, context: array<string, mixed>}>
     */
    public array $records = [];

    public function report(Message $message, array $context = []): Message
    {
        $this->records[] = [
            'message' => $message,
            'context' => $context,
        ];

        return $message;
    }

    public function reportBatch(iterable $records): array
    {
        $messages = [];

        foreach ($records as $record) {
            $messages[] = $this->report($record['message'], $record['context'] ?? []);
        }

        return $messages;
    }
}
