<?php

declare(strict_types=1);

namespace App\Tests\Core\Log;

use App\Core\Access\AccessActor;
use App\Core\Log\AuditLogger;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class AuditLoggerTest extends TestCase
{
    public function testItWritesAuditActionsWithActorContext(): void
    {
        $handler = new TestHandler();
        $monolog = new Logger('studio_audit');
        $monolog->pushHandler($handler);

        (new AuditLogger($monolog))->log(
            AccessActor::fromAccess(9, ['admin'], '10000000-0000-0000-0000-000000000001', 'admin'),
            'package.activate',
            [
                'package' => 'demo-module',
                'api_token' => 'secret',
            ],
        );

        $records = $handler->getRecords();

        self::assertCount(1, $records);
        self::assertSame(Level::Info, $records[0]->level);
        self::assertSame('package.activate', $records[0]->message);
        self::assertSame('admin', $records[0]->context['user']);
        self::assertSame(9, $records[0]->context['user_max_access_level']);
        self::assertSame('package.activate', $records[0]->context['action']);
        self::assertSame('demo-module', $records[0]->context['context']['package']);
        self::assertSame('[redacted]', $records[0]->context['context']['api_token']);
    }
}
