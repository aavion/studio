<?php

declare(strict_types=1);

namespace App\Tests\Core\Log;

use App\Core\Log\OperationLogger;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class OperationLoggerTest extends TestCase
{
    public function testItWritesFinishedOperationSummaries(): void
    {
        $handler = new TestHandler();
        $monolog = new Logger('studio_operation');
        $monolog->pushHandler($handler);

        (new OperationLogger($monolog))->logFinished([
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

        $records = $handler->getRecords();

        self::assertCount(1, $records);
        self::assertSame(Level::Info, $records[0]->level);
        self::assertSame('operation.finished', $records[0]->message);
        self::assertSame('backend.cache_clear', $records[0]->context['operation']);
        self::assertSame('success', $records[0]->context['status']);
        self::assertSame(1, $records[0]->context['entry_count']);
        self::assertSame(0, $records[0]->context['issue_count']);
        self::assertSame(1, $records[0]->context['message_count']);
        self::assertFalse($records[0]->context['can_continue']);
    }

    public function testItUsesReviewAndFailureLevels(): void
    {
        $handler = new TestHandler();
        $monolog = new Logger('studio_operation');
        $monolog->pushHandler($handler);
        $logger = new OperationLogger($monolog);

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

        $records = $handler->getRecords();

        self::assertSame(Level::Notice, $records[0]->level);
        self::assertSame('operation.requires_review', $records[0]->message);
        self::assertTrue($records[0]->context['can_continue']);
        self::assertSame(Level::Error, $records[1]->level);
        self::assertSame('operation.failed', $records[1]->message);
    }
}
