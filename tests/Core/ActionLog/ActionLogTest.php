<?php

declare(strict_types=1);

namespace App\Tests\Core\ActionLog;

use App\Core\ActionLog\ActionLog;
use App\Core\ActionLog\ActionLogEntry;
use App\Core\ActionLog\ActionLogStatus;
use App\Core\Workflow\OperationIssue;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ActionLogTest extends TestCase
{
    public function testItRecordsEntryLifecycle(): void
    {
        $startedAt = new DateTimeImmutable('2026-05-22 10:00:00.100000');
        $finishedAt = new DateTimeImmutable('2026-05-22 10:00:01.350000');

        $entry = ActionLogEntry::pending('composer install', ['command' => 'composer install'])
            ->start($startedAt)
            ->finish(ActionLogStatus::Success, context: ['exit_code' => 0], now: $finishedAt);

        self::assertSame('composer install', $entry->name());
        self::assertSame(ActionLogStatus::Success, $entry->status());
        self::assertSame(1250, $entry->durationMilliseconds());
        self::assertSame([
            'command' => 'composer install',
            'exit_code' => 0,
        ], $entry->context());
    }

    public function testItRecordsIssues(): void
    {
        $issue = OperationIssue::create('setup.warning', 'message.setup.warning');

        $entry = ActionLogEntry::pending('setup')
            ->start(new DateTimeImmutable('2026-05-22 10:00:00'))
            ->finish(ActionLogStatus::Warning, [$issue], now: new DateTimeImmutable('2026-05-22 10:00:01'));

        self::assertTrue($entry->hasIssues());
        self::assertSame([$issue], $entry->issues());
    }

    public function testItSummarizesStatuses(): void
    {
        $log = ActionLog::create()
            ->add(ActionLogEntry::pending('one')->finish(ActionLogStatus::Success))
            ->add(ActionLogEntry::pending('two')->finish(ActionLogStatus::Warning))
            ->add(ActionLogEntry::pending('three')->finish(ActionLogStatus::Failed));

        self::assertTrue($log->hasWarnings());
        self::assertTrue($log->hasFailures());
        self::assertSame([
            'failed' => 1,
            'success' => 1,
            'warning' => 1,
        ], $log->statusCounts());
    }

    public function testItExportsStructuredPayload(): void
    {
        $issue = OperationIssue::create('setup.warning', 'message.setup.warning');
        $startedAt = new DateTimeImmutable('2026-05-22 10:00:00.000000');
        $finishedAt = new DateTimeImmutable('2026-05-22 10:00:00.250000');
        $entry = ActionLogEntry::pending('setup', ['phase' => 'init'])
            ->start($startedAt)
            ->finish(ActionLogStatus::Warning, [$issue], ['exit_code' => 0], $finishedAt);

        $payload = ActionLog::create()->add($entry)->toArray();

        self::assertSame([
            'warning' => 1,
        ], $payload['status_counts']);
        self::assertFalse($payload['has_failures']);
        self::assertTrue($payload['has_warnings']);
        self::assertSame([
            'name' => 'setup',
            'status' => 'warning',
            'started_at' => '2026-05-22T10:00:00+00:00',
            'finished_at' => '2026-05-22T10:00:00+00:00',
            'duration_ms' => 250,
            'issues' => [[
                'code' => 'setup.warning',
                'translation_key' => 'message.setup.warning',
                'parameters' => [],
                'context' => [],
            ]],
            'context' => [
                'phase' => 'init',
                'exit_code' => 0,
            ],
        ], $payload['entries'][0]);
    }

    public function testItRejectsInvalidFinishStatus(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ActionLogEntry::pending('setup')->finish(ActionLogStatus::Running);
    }

    public function testItRejectsInvalidTiming(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ActionLogEntry::pending('setup')
            ->start(new DateTimeImmutable('2026-05-22 10:00:01'))
            ->finish(ActionLogStatus::Success, now: new DateTimeImmutable('2026-05-22 10:00:00'));
    }
}
