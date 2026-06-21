<?php

declare(strict_types=1);

namespace App\Tests\Core\Log;

use App\Content\Routing\ContentRouteLocalization;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Log\AccessRequestMetadata;
use App\Localization\TranslationLanguageCatalog;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AccessRequestMetadataTest extends TestCase
{
    public function testItDerivesOperationalRequestMetadata(): void
    {
        $metadata = new AccessRequestMetadata();
        $request = Request::create('/admin/logs', 'POST', server: [
            'HTTP_X_REQUEST_ID' => 'request-123',
            'HTTP_REFERER' => 'https://example.org/source?token=hidden#fragment',
            'HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9,en;q=0.8',
            'CONTENT_TYPE' => 'application/json; charset=utf-8',
        ]);
        $metadata->markStarted($request);
        $request->attributes->set('_route', 'backend_admin_route');

        self::assertMatchesRegularExpression('/\A[a-f0-9]{24}\z/', $metadata->requestId($request));
        self::assertSame('request-123', $metadata->correlationId($request));
        self::assertIsInt($metadata->durationMs($request));
        self::assertSame('admin', $metadata->surface($request));
        self::assertSame('api', $metadata->surface(Request::create('/api/v1/status')));
        self::assertSame('public', $metadata->surface(Request::create('/apiary')));
        self::assertSame('public', $metadata->surface(Request::create('/docs/api/reference')));
        self::assertSame('backend_admin_route', $metadata->resolvedRoute($request));
        self::assertSame('https://example.org/source', $metadata->referrer($request));
        self::assertSame('example.org', $metadata->referrerHost($request));
        self::assertSame('de-de', $metadata->preferredLanguage($request));
        self::assertSame('application/json', $metadata->contentType($request->headers->get('Content-Type')));
        self::assertSame(7, $metadata->responseSize(new Response('content')));
        self::assertSame([
            'request_id' => $metadata->requestId($request),
            'visitor_id' => 'visitor-a',
            'requested_path' => '/admin/logs',
            'resolved_route' => 'backend_admin_route',
        ], $metadata->trace($request, 'visitor-a'));
    }

    public function testItFallsBackToGeneratedRequestIdsForInvalidHeaderTokens(): void
    {
        $metadata = new AccessRequestMetadata();
        $request = Request::create('/admin/logs', server: [
            'HTTP_X_REQUEST_ID' => 'request 123',
            'HTTP_X_CORRELATION_ID' => str_repeat('a', 65),
        ]);

        $requestId = $metadata->requestId($request);

        self::assertMatchesRegularExpression('/\A[a-f0-9]{24}\z/', $requestId);
        self::assertSame($requestId, $metadata->requestId($request));
        self::assertSame('n/a', $metadata->correlationId($request));
    }

    public function testItKeepsValidInboundCorrelationSeparateFromInternalRequestId(): void
    {
        $metadata = new AccessRequestMetadata();
        $request = Request::create('/admin/logs', server: [
            'HTTP_X_REQUEST_ID' => 'short',
            'HTTP_X_CORRELATION_ID' => 'correlation-123',
        ]);

        self::assertMatchesRegularExpression('/\A[a-f0-9]{24}\z/', $metadata->requestId($request));
        self::assertSame('correlation-123', $metadata->correlationId($request));
    }

    public function testItRedactsSensitivePathSegments(): void
    {
        $metadata = new AccessRequestMetadata();
        $request = Request::create('/user/invitation/test-token');
        $request->attributes->set('_route', 'user_invitation_accept');
        $request->attributes->set('token', 'test-token');

        self::assertSame('/user/invitation/[redacted]', $metadata->sanitizedPath($request));
        self::assertSame('/user/invitation/[redacted]', $metadata->trace($request, 'visitor-a')['requested_path']);
    }

    public function testItRedactsSensitiveReferrerPathSegments(): void
    {
        $metadata = new AccessRequestMetadata();
        $request = Request::create('/docs', server: [
            'HTTP_REFERER' => 'https://example.org/user/invitation/test-token?utm=source#fragment',
        ]);

        self::assertSame('https://example.org/user/invitation/[redacted]', $metadata->referrer($request));
    }

    public function testItGatesLocalizedSurfacePrefixesByRouteLocaleOrEnabledRoutePrefixes(): void
    {
        $disabled = new AccessRequestMetadata($this->routeLocalization(false));
        $enabled = new AccessRequestMetadata($this->routeLocalization(true));
        $localizedRoute = Request::create('/de/admin/logs');
        $localizedRoute->attributes->set('_locale', 'de');

        self::assertSame('public', $disabled->surface(Request::create('/de/admin')));
        self::assertSame('public', $disabled->surface(Request::create('/de/api/v1/status')));
        self::assertSame('admin', $disabled->surface($localizedRoute));
        self::assertSame('admin', $enabled->surface(Request::create('/de/admin/logs')));
        self::assertSame('public', $enabled->surface(Request::create('/de/api/v1/status')));
    }

    private function routeLocalization(bool $enabled): ContentRouteLocalization
    {
        $config = new Config($this->connection());
        $config->set(ContentRouteLocalization::ENABLED_KEY, $enabled, ConfigValueType::Boolean);

        return new ContentRouteLocalization($config, new TranslationLanguageCatalog(dirname(__DIR__, 3)));
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');

        return $connection;
    }
}
