<?php

declare(strict_types=1);

namespace App\Tests\Core\Statistics;

use App\Core\Statistics\FileVisitorIdentityStore;
use App\Core\Statistics\VisitorIdGenerator;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class VisitorIdGeneratorTest extends TestCase
{
    use FilesystemTestHelper;

    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = $this->createTemporaryDirectory('system-visitor-identity');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->cacheDir);
    }

    public function testItGeneratesStableVisitorCookieIds(): void
    {
        $request = Request::create('/docs', server: [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'Studio Browser/1.0',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.23, 203.0.113.10',
        ]);
        $generator = $this->generator();
        $response = new Response();
        $generator->attachCookie($request, $response);
        $fallbackId = $generator->generate($request);
        $cookie = $response->headers->getCookies()[0] ?? null;

        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{22}\z/', $fallbackId);
        self::assertSame($fallbackId, $generator->generate($request));
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
        $cookieId = $generator->generate($nextRequest);

        self::assertSame($fallbackId, $cookieId);
        self::assertSame($cookieId, $generator->generate($nextRequest));
        self::assertSame('203.0.113.10', $generator->sourceIp($request));
        self::assertSame('198.51.100.23', $generator->proxyClientIp($request));
        self::assertSame(['198.51.100.23', '203.0.113.10'], $generator->proxyIpChain($request));
    }

    public function testItRejectsForgedVisitorCookies(): void
    {
        $request = Request::create('/docs');
        $request->cookies->set(VisitorIdGenerator::COOKIE_NAME, 'v1.forged-token.invalid-signature');
        $generator = $this->generator();
        $visitorId = $generator->generate($request);
        $response = new Response();
        $generator->attachCookie($request, $response);
        $cookie = $response->headers->getCookies()[0] ?? null;

        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{22}\z/', $visitorId);
        self::assertNotNull($cookie);
        self::assertNotSame('v1.forged-token.invalid-signature', $cookie->getValue());
    }

    public function testItKeepsCookieLessVisitorsStableByIpAndUserAgent(): void
    {
        $generator = $this->generator();
        $server = [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'Shared Browser/1.0',
        ];

        self::assertSame(
            $generator->generate(Request::create('/docs', server: $server)),
            $generator->generate(Request::create('/docs', server: $server)),
        );
    }

    public function testItUsesForwardingHeaderEntropyOnlyForCookieLessVisitorFallbacks(): void
    {
        $generator = new VisitorIdGenerator('test-secret');
        $baseServer = [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'Shared Browser/1.0',
        ];
        $firstRequest = Request::create('/docs', server: [
            ...$baseServer,
            'HTTP_X_FORWARDED_FOR' => '198.51.100.10, 203.0.113.10',
        ]);
        $secondRequest = Request::create('/docs', server: [
            ...$baseServer,
            'HTTP_X_FORWARDED_FOR' => '198.51.100.11, 203.0.113.10',
        ]);
        $matchingRequest = Request::create('/docs', server: [
            ...$baseServer,
            'HTTP_X_FORWARDED_FOR' => '198.51.100.10, 203.0.113.10',
        ]);

        self::assertSame($generator->generate($firstRequest), $generator->generate($matchingRequest));
        self::assertNotSame($generator->generate($firstRequest), $generator->generate($secondRequest));
        self::assertSame('203.0.113.10', $generator->sourceIp($firstRequest));
    }

    public function testItSeparatesRecentFallbacksBehindSameIpWhenForwardingEntropyDiffers(): void
    {
        $generator = $this->generator();
        $baseServer = [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'Shared Browser/1.0',
        ];
        $firstRequest = Request::create('/docs', server: [
            ...$baseServer,
            'HTTP_X_FORWARDED_FOR' => '198.51.100.10, 203.0.113.10',
        ]);
        $secondRequest = Request::create('/docs', server: [
            ...$baseServer,
            'HTTP_X_FORWARDED_FOR' => '198.51.100.11, 203.0.113.10',
        ]);
        $matchingRequest = Request::create('/docs', server: [
            ...$baseServer,
            'HTTP_X_FORWARDED_FOR' => '198.51.100.10, 203.0.113.10',
        ]);

        self::assertSame($generator->generate($firstRequest), $generator->generate($matchingRequest));
        self::assertNotSame($generator->generate($firstRequest), $generator->generate($secondRequest));
    }

    public function testItUsesPendingCookieVisitorIdsWithoutAStore(): void
    {
        $request = Request::create('/docs', server: [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'Studio Browser/1.0',
        ]);
        $generator = new VisitorIdGenerator('test-secret');
        $response = new Response();

        $generator->attachCookie($request, $response);
        $cookie = $response->headers->getCookies()[0] ?? null;

        self::assertNotNull($cookie);

        $nextRequest = Request::create('/docs', server: [
            'REMOTE_ADDR' => '198.51.100.50',
            'HTTP_USER_AGENT' => 'Another Browser/2.0',
        ]);
        $nextRequest->cookies->set(VisitorIdGenerator::COOKIE_NAME, $cookie->getValue());

        self::assertSame($generator->generate($request), $generator->generate($nextRequest));
    }

    public function testItDoesNotIssueADifferentCookieAfterFallbackResolutionWithoutAStore(): void
    {
        $request = Request::create('/docs', server: [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'Studio Browser/1.0',
        ]);
        $generator = new VisitorIdGenerator('test-secret');
        $visitorId = $generator->generate($request);
        $response = new Response();

        $generator->attachCookie($request, $response);

        self::assertSame($visitorId, $generator->generate($request));
        self::assertSame([], $response->headers->getCookies());
    }

    public function testItBindsNewVisitorCookiesToRecentSharedIpAndUserAgentFallbacks(): void
    {
        $server = [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'Shared Browser/1.0',
        ];
        $generator = $this->generator();
        $firstRequest = Request::create('/docs', server: $server);
        $secondRequest = Request::create('/docs', server: $server);
        $firstResponse = new Response();
        $secondResponse = new Response();

        self::assertSame($generator->generate($firstRequest), $generator->generate($secondRequest));

        $generator->attachCookie($firstRequest, $firstResponse);
        $generator->attachCookie($secondRequest, $secondResponse);
        $firstCookie = $firstResponse->headers->getCookies()[0] ?? null;
        $secondCookie = $secondResponse->headers->getCookies()[0] ?? null;

        self::assertNotNull($firstCookie);
        self::assertNotNull($secondCookie);
        self::assertNotSame($firstCookie->getValue(), $secondCookie->getValue());

        $firstCookieRequest = Request::create('/docs', server: $server);
        $secondCookieRequest = Request::create('/docs', server: $server);
        $firstCookieRequest->cookies->set(VisitorIdGenerator::COOKIE_NAME, $firstCookie->getValue());
        $secondCookieRequest->cookies->set(VisitorIdGenerator::COOKIE_NAME, $secondCookie->getValue());

        self::assertSame($generator->generate($firstCookieRequest), $generator->generate($secondCookieRequest));
    }

    public function testItChangesIdsWhenTheSecretChanges(): void
    {
        $request = Request::create('/docs', server: [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'Studio Browser/1.0',
        ]);
        $generator = $this->generator('one-secret');
        $response = new Response();
        $generator->attachCookie($request, $response);
        $cookie = $response->headers->getCookies()[0] ?? null;

        self::assertNotNull($cookie);

        $nextRequest = Request::create('/docs');
        $nextRequest->cookies->set(VisitorIdGenerator::COOKIE_NAME, $cookie->getValue());

        self::assertNotSame(
            $generator->generate($request),
            $this->generator('other-secret')->generate($nextRequest),
        );
    }

    private function generator(string $secret = 'test-secret'): VisitorIdGenerator
    {
        return new VisitorIdGenerator($secret, new FileVisitorIdentityStore($this->cacheDir, 'test'));
    }
}
