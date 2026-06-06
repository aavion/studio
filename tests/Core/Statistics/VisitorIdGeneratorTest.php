<?php

declare(strict_types=1);

namespace App\Tests\Core\Statistics;

use App\Core\Statistics\VisitorIdGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class VisitorIdGeneratorTest extends TestCase
{
    public function testItGeneratesStableVisitorCookieIds(): void
    {
        $request = Request::create('/docs', server: [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'Studio Browser/1.0',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.23, 203.0.113.10',
        ]);
        $generator = new VisitorIdGenerator('test-secret');
        $response = new Response();
        $firstId = $generator->generate($request);
        $generator->attachCookie($request, $response);
        $cookie = $response->headers->getCookies()[0] ?? null;

        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{22}\z/', $firstId);
        self::assertSame($firstId, $generator->generate($request));
        self::assertNotNull($cookie);
        self::assertSame(VisitorIdGenerator::COOKIE_NAME, $cookie->getName());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', strtolower((string) $cookie->getSameSite()));
        self::assertGreaterThanOrEqual(time() + 2_591_990, $cookie->getExpiresTime());
        self::assertLessThanOrEqual(time() + 2_592_010, $cookie->getExpiresTime());

        $nextRequest = Request::create('/docs', server: [
            'REMOTE_ADDR' => '198.51.100.50',
            'HTTP_USER_AGENT' => 'Another Browser/2.0',
        ]);
        $nextRequest->cookies->set(VisitorIdGenerator::COOKIE_NAME, $cookie->getValue());

        self::assertSame($firstId, $generator->generate($nextRequest));
        self::assertSame('203.0.113.10', $generator->sourceIp($request));
        self::assertSame('198.51.100.23', $generator->proxyClientIp($request));
        self::assertSame(['198.51.100.23', '203.0.113.10'], $generator->proxyIpChain($request));
    }

    public function testItRejectsForgedVisitorCookies(): void
    {
        $request = Request::create('/docs');
        $request->cookies->set(VisitorIdGenerator::COOKIE_NAME, 'v1.forged-token.invalid-signature');
        $generator = new VisitorIdGenerator('test-secret');
        $visitorId = $generator->generate($request);
        $response = new Response();
        $generator->attachCookie($request, $response);
        $cookie = $response->headers->getCookies()[0] ?? null;

        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{22}\z/', $visitorId);
        self::assertNotNull($cookie);
        self::assertNotSame('v1.forged-token.invalid-signature', $cookie->getValue());
    }

    public function testItSeparatesVisitorsThatShareIpAndUserAgentWithoutCookies(): void
    {
        $generator = new VisitorIdGenerator('test-secret');
        $server = [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'Shared Browser/1.0',
        ];

        self::assertNotSame(
            $generator->generate(Request::create('/docs', server: $server)),
            $generator->generate(Request::create('/docs', server: $server)),
        );
    }

    public function testItChangesIdsWhenTheSecretChanges(): void
    {
        $request = Request::create('/docs', server: [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'Studio Browser/1.0',
        ]);
        $generator = new VisitorIdGenerator('one-secret');
        $response = new Response();
        $generator->attachCookie($request, $response);
        $cookie = $response->headers->getCookies()[0] ?? null;

        self::assertNotNull($cookie);

        $nextRequest = Request::create('/docs');
        $nextRequest->cookies->set(VisitorIdGenerator::COOKIE_NAME, $cookie->getValue());

        self::assertNotSame(
            $generator->generate($request),
            (new VisitorIdGenerator('other-secret'))->generate($nextRequest),
        );
    }
}
