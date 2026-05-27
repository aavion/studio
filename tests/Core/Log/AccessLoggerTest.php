<?php

declare(strict_types=1);

namespace App\Tests\Core\Log;

use App\Core\Log\AccessLogger;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AccessLoggerTest extends TestCase
{
    public function testItWritesAccessEntriesWithGeoPlaceholders(): void
    {
        $handler = new TestHandler();
        $monolog = new Logger('studio_access');
        $monolog->pushHandler($handler);
        $request = Request::create('/admin/logs?level=error', 'POST', server: ['REMOTE_ADDR' => '203.0.113.10']);
        $request->attributes->set('_route', 'backend_admin_route');

        (new AccessLogger($monolog))->log($request, new Response('', 401));

        $records = $handler->getRecords();

        self::assertCount(1, $records);
        self::assertSame(Level::Info, $records[0]->level);
        self::assertSame('access.request', $records[0]->message);
        self::assertSame('POST', $records[0]->context['method']);
        self::assertSame('/admin/logs', $records[0]->context['path']);
        self::assertSame('backend_admin_route', $records[0]->context['route']);
        self::assertSame('level=error', $records[0]->context['query_string']);
        self::assertSame(401, $records[0]->context['http_status']);
        self::assertSame('203.0.113.10', $records[0]->context['ip']);
        self::assertSame('n/a', $records[0]->context['city']);
        self::assertSame('n/a', $records[0]->context['state']);
        self::assertSame('n/a', $records[0]->context['country']);
        self::assertSame('n/a', $records[0]->context['continent']);
    }
}
