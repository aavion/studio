<?php

declare(strict_types=1);

namespace App\Tests\Security\Abuse;

use App\Api\Http\ApiRequestContext;
use App\Core\Statistics\VisitorIdGenerator;
use App\Entity\ApiKey;
use App\Entity\UserAccount;
use App\Security\Abuse\AbuseSubjectResolver;
use App\Security\Abuse\AbuseSubjectType;
use App\Security\ApiKeyStatus;
use App\Security\UserRole;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

final class AbuseSubjectResolverTest extends TestCase
{
    public function testItResolvesVisitorAndIpBucketSubjectsWithoutTrustingForwardingHeaders(): void
    {
        $resolver = new AbuseSubjectResolver(new VisitorIdGenerator('test-secret'), new TokenStorage(), 'test-secret');
        $request = Request::create('/docs', server: [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'Shared Browser/1.0',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.10, 203.0.113.10',
        ]);

        $resolution = $resolver->resolve($request);
        $visitor = $resolution->first(AbuseSubjectType::Visitor);
        $ipBucket = $resolution->first(AbuseSubjectType::IpBucket);
        $encoded = json_encode($resolution->toArray(), JSON_THROW_ON_ERROR);

        self::assertNotNull($visitor);
        self::assertSame($visitor, $resolution->primary());
        self::assertNotNull($ipBucket);
        self::assertTrue($ipBucket->ipDerived());
        self::assertStringNotContainsString('203.0.113.10', $encoded);
        self::assertStringNotContainsString('198.51.100.10', $encoded);
    }

    public function testItKeepsIpBucketStableWhenCookieLessForwardingEntropyChanges(): void
    {
        $resolver = new AbuseSubjectResolver(new VisitorIdGenerator('test-secret'), new TokenStorage(), 'test-secret');
        $baseServer = [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'Shared Browser/1.0',
        ];
        $first = $resolver->resolve(Request::create('/docs', server: [
            ...$baseServer,
            'HTTP_X_FORWARDED_FOR' => '198.51.100.10, 203.0.113.10',
        ]));
        $second = $resolver->resolve(Request::create('/docs', server: [
            ...$baseServer,
            'HTTP_X_FORWARDED_FOR' => '198.51.100.11, 203.0.113.10',
        ]));

        self::assertNotSame(
            $first->first(AbuseSubjectType::Visitor)?->identifier(),
            $second->first(AbuseSubjectType::Visitor)?->identifier(),
        );
        self::assertSame(
            $first->first(AbuseSubjectType::IpBucket)?->identifier(),
            $second->first(AbuseSubjectType::IpBucket)?->identifier(),
        );
    }

    public function testItAddsAuthenticatedApiKeyAndUserSubjectsFromApiContext(): void
    {
        $resolver = new AbuseSubjectResolver(new VisitorIdGenerator('test-secret'), new TokenStorage(), 'test-secret');
        $request = Request::create('/api/v1/content', server: ['REMOTE_ADDR' => '203.0.113.10']);
        $user = new UserAccount('99999999-0000-7000-8000-000000000101', 'owner', 'owner@example.test', 'hash', role: UserRole::Owner);
        $apiKey = new ApiKey(
            '99999999-0000-7000-8000-000000000201',
            'testkey',
            str_repeat('a', 64),
            'encrypted',
            $user,
            ApiKeyStatus::ReadWrite,
        );
        ApiRequestContext::fromApiKey($apiKey)->attachTo($request);

        $resolution = $resolver->resolve($request);

        self::assertSame($user->uid(), $resolution->first(AbuseSubjectType::User)?->identifier());
        self::assertSame($apiKey->uid(), $resolution->first(AbuseSubjectType::ApiKey)?->identifier());
        self::assertSame('testkey', $resolution->first(AbuseSubjectType::ApiKey)?->context()['prefix']);
    }

    public function testItKeepsInvalidBearerTokensToSafePrefixSubjects(): void
    {
        $resolver = new AbuseSubjectResolver(new VisitorIdGenerator('test-secret'), new TokenStorage(), 'test-secret');
        $request = Request::create('/api/v1/content', server: [
            'HTTP_AUTHORIZATION' => 'Bearer publicPrefix.secret-token-material',
        ]);

        $subject = $resolver->resolve($request)->first(AbuseSubjectType::ApiKeyPrefix);

        self::assertNotNull($subject);
        self::assertSame('publicPrefix', $subject->identifier());
        self::assertStringNotContainsString('secret-token-material', json_encode($subject->toArray(), JSON_THROW_ON_ERROR));
    }
}
