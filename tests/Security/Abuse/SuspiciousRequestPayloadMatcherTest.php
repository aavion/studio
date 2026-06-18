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

    public function testItDetectsJsonBodyAttackSignaturesWithoutReturningRawPayloads(): void
    {
        $match = (new SuspiciousRequestPayloadMatcher())->match(Request::create(
            '/api/v1/search',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'filter' => [
                    'query' => "x' UNION SELECT password FROM users --",
                ],
            ], JSON_THROW_ON_ERROR),
        ));

        self::assertIsArray($match);
        self::assertContains('sql_union_select', $match['signatures']);
        self::assertSame('json', $match['parameters'][0]['source']);
        self::assertSame('filter.query', $match['parameters'][0]['name']);
        self::assertStringNotContainsString('UNION SELECT', json_encode($match, JSON_THROW_ON_ERROR));
    }

    public function testItDetectsJsonLikeRawBodyAttackSignatures(): void
    {
        $match = (new SuspiciousRequestPayloadMatcher())->match(Request::create(
            '/api/v1/search',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"query":"../../etc/passwd"',
        ));

        self::assertIsArray($match);
        self::assertContains('sensitive_file_probe', $match['signatures']);
        self::assertSame('raw_body', $match['parameters'][0]['source']);
        self::assertSame('body', $match['parameters'][0]['name']);
        self::assertStringNotContainsString('/etc/passwd', json_encode($match, JSON_THROW_ON_ERROR));
    }
}
