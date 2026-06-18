<?php

declare(strict_types=1);

namespace App\Tests\Api\Security;

use App\Api\Security\ApiRequestMethodPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ApiRequestMethodPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{Request, string, bool}>
     */
    public static function effectiveMethodCases(): iterable
    {
        yield 'ordinary get' => [
            Request::create('/api/v1/status'),
            Request::METHOD_GET,
            true,
        ];
        yield 'ordinary post' => [
            Request::create('/api/v1/content/items', Request::METHOD_POST),
            Request::METHOD_POST,
            false,
        ];
        yield 'preflight uses requested unsafe method' => [
            Request::create('/api/v1/content/items', Request::METHOD_OPTIONS, server: [
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => Request::METHOD_PATCH,
            ]),
            Request::METHOD_PATCH,
            false,
        ];
        yield 'credentialed preflight uses requested unsafe method' => [
            Request::create('/api/v1/content/items', Request::METHOD_OPTIONS, server: [
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => Request::METHOD_PATCH,
                'HTTP_AUTHORIZATION' => 'Basic credential-probe',
            ]),
            Request::METHOD_PATCH,
            false,
        ];
        yield 'malformed bearer preflight still uses requested unsafe method' => [
            Request::create('/api/v1/content/items', Request::METHOD_OPTIONS, server: [
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => Request::METHOD_DELETE,
                'HTTP_AUTHORIZATION' => 'Bearer',
            ]),
            Request::METHOD_DELETE,
            false,
        ];
        yield 'credentialed preflight without requested method defaults to read' => [
            Request::create('/api/v1/content/items', Request::METHOD_OPTIONS, server: [
                'HTTP_AUTHORIZATION' => 'Bearer',
            ]),
            Request::METHOD_GET,
            true,
        ];
    }

    #[DataProvider('effectiveMethodCases')]
    public function testEffectiveMethod(Request $request, string $method, bool $safe): void
    {
        $policy = new ApiRequestMethodPolicy();

        self::assertSame($method, $policy->effectiveMethod($request));
        self::assertSame($safe, $policy->isSafeEffectiveMethod($request));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function apiPathCases(): iterable
    {
        yield 'api root' => ['/api/v1', true];
        yield 'api child' => ['/api/v1/status', true];
        yield 'api lookalike' => ['/api/v10/status', false];
        yield 'browser content' => ['/docs/api/v1', false];
    }

    #[DataProvider('apiPathCases')]
    public function testApiPathUsesSegmentBoundaries(string $path, bool $api): void
    {
        self::assertSame($api, (new ApiRequestMethodPolicy())->isApiV1Request(Request::create($path)));
    }

    public function testApiPathUsesLocalizedRequestSegments(): void
    {
        $request = Request::create('/de/api/v1/status');
        $request->attributes->set('_locale', 'de');

        self::assertTrue((new ApiRequestMethodPolicy())->isApiV1Request($request));
        self::assertFalse((new ApiRequestMethodPolicy())->isApiV1Request(Request::create('/de/api/v1/status')));
    }
}
