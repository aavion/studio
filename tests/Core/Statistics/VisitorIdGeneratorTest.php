<?php

declare(strict_types=1);

namespace App\Tests\Core\Statistics;

use App\Core\Statistics\VisitorIdGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class VisitorIdGeneratorTest extends TestCase
{
    public function testItGeneratesStableIdsFromProxyIpAndUserAgent(): void
    {
        $request = Request::create('/docs', server: [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'Studio Browser/1.0',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.23, 203.0.113.10',
        ]);
        $generator = new VisitorIdGenerator('test-secret');

        self::assertSame($generator->generate($request), $generator->generate($request));
        self::assertSame('198.51.100.23', $generator->sourceIp($request));
        self::assertSame('198.51.100.23', $generator->proxyClientIp($request));
        self::assertSame(['198.51.100.23', '203.0.113.10'], $generator->proxyIpChain($request));
    }

    public function testItChangesIdsWhenTheSecretChanges(): void
    {
        $request = Request::create('/docs', server: [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'Studio Browser/1.0',
        ]);

        self::assertNotSame(
            (new VisitorIdGenerator('one-secret'))->generate($request),
            (new VisitorIdGenerator('other-secret'))->generate($request),
        );
    }
}
