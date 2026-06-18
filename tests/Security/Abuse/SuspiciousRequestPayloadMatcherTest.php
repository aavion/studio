<?php

declare(strict_types=1);

namespace App\Tests\Security\Abuse;

use App\Security\Abuse\SuspiciousRequestPayloadMatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class SuspiciousRequestPayloadMatcherTest extends TestCase
{
    public function testItDetectsObviousAttackSignaturesWithoutReturningRawPayloads(): void
    {
        $match = (new SuspiciousRequestPayloadMatcher())->match(Request::create('/search', 'GET', [
            'q' => "x' UNION SELECT password FROM users --",
            'file' => '../../etc/passwd',
        ]));

        self::assertIsArray($match);
        self::assertContains('sql_union_select', $match['signatures']);
        self::assertContains('sensitive_file_probe', $match['signatures']);
        self::assertSame('q', $match['parameters'][0]['name']);
        self::assertStringNotContainsString('UNION SELECT', json_encode($match, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('/etc/passwd', json_encode($match, JSON_THROW_ON_ERROR));
    }

    public function testItDetectsMalformedSecurityParametersButAllowsOrdinaryArrays(): void
    {
        $matcher = new SuspiciousRequestPayloadMatcher();

        $malformed = $matcher->match(Request::create('/user/login', 'POST', [
            'username' => ['owner'],
        ]));
        $ordinary = $matcher->match(Request::create('/search', 'GET', [
            'tags' => ['one', 'two'],
        ]));

        self::assertIsArray($malformed);
        self::assertContains('malformed_parameter', $malformed['signatures']);
        self::assertSame('username', $malformed['parameters'][0]['name']);
        self::assertNull($ordinary);
    }
}
