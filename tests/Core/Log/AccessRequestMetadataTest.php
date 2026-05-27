<?php

declare(strict_types=1);

namespace App\Tests\Core\Log;

use App\Core\Log\AccessRequestMetadata;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AccessRequestMetadataTest extends TestCase
{
    public function testItDerivesOperationalRequestMetadata(): void
    {
        $metadata = new AccessRequestMetadata();
        $request = Request::create('/admin/logs', 'POST', server: [
            'HTTP_X_REQUEST_ID' => 'request 123',
            'HTTP_REFERER' => 'https://example.org/source?token=hidden#fragment',
            'HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9,en;q=0.8',
            'CONTENT_TYPE' => 'application/json; charset=utf-8',
        ]);
        $metadata->markStarted($request);
        $request->attributes->set('_route', 'backend_admin_route');

        self::assertSame('request123', $metadata->requestId($request));
        self::assertIsInt($metadata->durationMs($request));
        self::assertSame('admin', $metadata->surface($request));
        self::assertSame('backend_admin_route', $metadata->resolvedRoute($request));
        self::assertSame('https://example.org/source', $metadata->referrer($request));
        self::assertSame('example.org', $metadata->referrerHost($request));
        self::assertSame('de-de', $metadata->preferredLanguage($request));
        self::assertSame('application/json', $metadata->contentType($request->headers->get('Content-Type')));
        self::assertSame(7, $metadata->responseSize(new Response('content')));
        self::assertSame([
            'request_id' => 'request123',
            'visitor_id' => 'visitor-a',
            'requested_path' => '/admin/logs',
            'resolved_route' => 'backend_admin_route',
        ], $metadata->trace($request, 'visitor-a'));
    }
}
