<?php

declare(strict_types=1);

namespace App\Tests\Core\Log;

use App\Core\Log\AccessLogger;
use App\Core\Log\AccessRequestMetadata;
use App\Core\Geo\NullGeoIpResolver;
use App\Core\Statistics\VisitorIdGenerator;
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
        $monolog = new Logger('system_access');
        $monolog->pushHandler($handler);
        $request = Request::create('/admin/logs?level=error&reset_token=hidden&filter[code]=oauth-code&auth=api-secret', 'POST', server: [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'Studio Browser/1.0',
            'HTTP_X_REQUEST_ID' => 'edge-request-1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.23, 203.0.113.10',
            'HTTP_REFERER' => 'https://example.org/source?token=hidden',
            'HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9,en;q=0.8',
            'CONTENT_TYPE' => 'application/json; charset=utf-8',
        ]);
        $request->attributes->set('_route', 'backend_admin_route');

        $visitorIdGenerator = new VisitorIdGenerator('test-secret');
        $metadata = new AccessRequestMetadata();
        $metadata->markStarted($request);
        $response = new Response('Denied', 401, ['Content-Type' => 'text/html; charset=UTF-8']);

        (new AccessLogger($monolog, $visitorIdGenerator, $metadata, new NullGeoIpResolver()))->log($request, $response);

        $records = $handler->getRecords();

        self::assertCount(1, $records);
        self::assertSame(Level::Info, $records[0]->level);
        self::assertSame('access.request', $records[0]->message);
        self::assertSame('POST', $records[0]->context['method']);
        self::assertSame('/admin/logs', $records[0]->context['path']);
        self::assertSame('/admin/logs', $records[0]->context['requested_path']);
        self::assertSame('backend_admin_route', $records[0]->context['route']);
        self::assertSame('backend_admin_route', $records[0]->context['resolved_route']);
        self::assertSame('admin', $records[0]->context['surface']);
        self::assertSame(
            'auth=%5Bredacted%5D&filter%5Bcode%5D=%5Bredacted%5D&level=error&reset_token=%5Bredacted%5D',
            $records[0]->context['query_string'],
        );
        self::assertSame(401, $records[0]->context['http_status']);
        self::assertIsString($records[0]->context['request_id']);
        self::assertNotSame('edge-request-1', $records[0]->context['request_id']);
        self::assertSame('edge-request-1', $records[0]->context['correlation_id']);
        self::assertIsInt($records[0]->context['duration_ms']);
        self::assertSame($visitorIdGenerator->generate($request), $records[0]->context['visitor_id']);
        self::assertSame('http', $records[0]->context['scheme']);
        self::assertSame('localhost', $records[0]->context['host']);
        self::assertSame('203.0.113.10', $records[0]->context['ip']);
        self::assertSame('203.0.113.10', $records[0]->context['client_ip']);
        self::assertSame('198.51.100.23', $records[0]->context['proxy_client_ip']);
        self::assertSame(['198.51.100.23', '203.0.113.10'], $records[0]->context['proxy_ip_chain']);
        self::assertSame('Studio Browser/1.0', $records[0]->context['user_agent']);
        self::assertSame('https://example.org/source', $records[0]->context['referrer']);
        self::assertSame('example.org', $records[0]->context['referrer_host']);
        self::assertSame('de-DE,de;q=0.9,en;q=0.8', $records[0]->context['accept_language']);
        self::assertSame('de-de', $records[0]->context['preferred_language']);
        self::assertSame('application/json', $records[0]->context['request_content_type']);
        self::assertSame('text/html', $records[0]->context['response_content_type']);
        self::assertSame(6, $records[0]->context['response_size']);
        self::assertSame('n/a', $records[0]->context['city']);
        self::assertSame('n/a', $records[0]->context['state']);
        self::assertSame('n/a', $records[0]->context['country']);
        self::assertSame('n/a', $records[0]->context['continent']);
    }

    public function testItRedactsTokenizedPathSegments(): void
    {
        $handler = new TestHandler();
        $monolog = new Logger('system_access');
        $monolog->pushHandler($handler);
        $request = Request::create('/user/invitation/test-token', 'GET', server: [
            'REMOTE_ADDR' => '203.0.113.10',
        ]);
        $request->attributes->set('_route', 'user_invitation_accept');
        $request->attributes->set('token', 'test-token');

        $visitorIdGenerator = new VisitorIdGenerator('test-secret');
        $metadata = new AccessRequestMetadata();

        (new AccessLogger($monolog, $visitorIdGenerator, $metadata, new NullGeoIpResolver()))->log($request, new Response('', 200));

        $records = $handler->getRecords();

        self::assertSame('/user/invitation/[redacted]', $records[0]->context['path']);
        self::assertSame('/user/invitation/[redacted]', $records[0]->context['requested_path']);
        self::assertStringNotContainsString('test-token', json_encode($records[0]->context, JSON_THROW_ON_ERROR));
    }

    public function testItRedactsTokenizedReferrerPathSegments(): void
    {
        $handler = new TestHandler();
        $monolog = new Logger('system_access');
        $monolog->pushHandler($handler);
        $request = Request::create('/docs', 'GET', server: [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_REFERER' => 'https://example.org/user/invitation/test-token?utm=source',
        ]);

        (new AccessLogger($monolog, new VisitorIdGenerator('test-secret'), new AccessRequestMetadata(), new NullGeoIpResolver()))->log($request, new Response('', 200));

        $records = $handler->getRecords();

        self::assertSame('https://example.org/user/invitation/[redacted]', $records[0]->context['referrer']);
        self::assertStringNotContainsString('test-token', json_encode($records[0]->context, JSON_THROW_ON_ERROR));
    }
}
