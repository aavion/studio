<?php

declare(strict_types=1);

namespace App\Tests\Core\Log;

use App\Core\Access\AccessActor;
use App\Core\Log\AccessRequestMetadata;
use App\Core\Log\AuditLogPolicyInterface;
use App\Core\Log\AuditLogger;
use App\Core\Statistics\VisitorIdGenerator;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class AuditLoggerTest extends TestCase
{
    public function testItWritesAuditActionsWithActorContext(): void
    {
        $handler = new TestHandler();
        $monolog = new Logger('studio_audit');
        $monolog->pushHandler($handler);

        (new AuditLogger($monolog))->log(
            AccessActor::fromAccess(9, ['site_operations'], '10000000-0000-7000-8000-000000000001', 'admin'),
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
        self::assertSame(9, $records[0]->context['user_access_level']);
        self::assertSame('package.activate', $records[0]->context['action']);
        self::assertSame('demo-module', $records[0]->context['context']['package']);
        self::assertSame('[redacted]', $records[0]->context['context']['api_token']);
    }

    public function testItSkipsActionsDeniedByPolicy(): void
    {
        $handler = new TestHandler();
        $monolog = new Logger('studio_audit');
        $monolog->pushHandler($handler);

        (new AuditLogger($monolog, new DenyAllAuditLogPolicy()))->log(
            AccessActor::fromAccess(9, ['site_operations'], '10000000-0000-7000-8000-000000000001', 'admin'),
            'settings.core.save',
        );

        self::assertSame([], $handler->getRecords());
    }

    public function testItAddsCurrentRequestTraceWhenAvailable(): void
    {
        $handler = new TestHandler();
        $monolog = new Logger('studio_audit');
        $monolog->pushHandler($handler);
        $request = Request::create('/comments', 'POST', [], [], [], [
            'HTTP_USER_AGENT' => 'Example Browser',
            'HTTP_X_REQUEST_ID' => 'comment-request-1',
            'REMOTE_ADDR' => '203.0.113.20',
        ]);
        $request->attributes->set('_route', 'comment_create');
        $requestStack = new RequestStack();
        $requestStack->push($request);
        $visitorIdGenerator = new VisitorIdGenerator('test-secret');

        (new AuditLogger(
            $monolog,
            requestStack: $requestStack,
            accessRequestMetadata: new AccessRequestMetadata(),
            visitorIdGenerator: $visitorIdGenerator,
        ))->log(
            AccessActor::anonymous(),
            'comment.create',
            ['content_uid' => 'content-1'],
        );

        $records = $handler->getRecords();

        self::assertCount(1, $records);
        self::assertSame('content-1', $records[0]->context['context']['content_uid']);
        self::assertSame('comment-request-1', $records[0]->context['context']['request_id']);
        self::assertSame($visitorIdGenerator->generate($request), $records[0]->context['context']['visitor_id']);
        self::assertSame('/comments', $records[0]->context['context']['requested_path']);
        self::assertSame('comment_create', $records[0]->context['context']['resolved_route']);
    }
}

final class DenyAllAuditLogPolicy implements AuditLogPolicyInterface
{
    public function allows(string $action): bool
    {
        return false;
    }
}
