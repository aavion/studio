<?php

declare(strict_types=1);

namespace App\Tests\Core\Routing;

use App\Core\Routing\PathScopeMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PathScopeMatcherTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function prefixCases(): iterable
    {
        yield 'exact path' => ['/api/v1', '/api/v1', true];
        yield 'child path' => ['/api/v1/status', '/api/v1', true];
        yield 'lookalike path' => ['/api/v10/status', '/api/v1', false];
        yield 'segment sibling' => ['/cronjobs', '/cron', false];
        yield 'prefix without leading slash' => ['/build/app.js', 'build', true];
        yield 'root does not match every path' => ['/docs', '/', false];
        yield 'root matches root' => ['/', '/', true];
    }

    #[DataProvider('prefixCases')]
    public function testMatchesPrefixUsesSegmentBoundaries(string $path, string $prefix, bool $matches): void
    {
        self::assertSame($matches, (new PathScopeMatcher())->matchesPrefix($path, $prefix));
    }

    public function testMatchesAnyPrefixUsesSameSegmentRules(): void
    {
        $matcher = new PathScopeMatcher();

        self::assertTrue($matcher->matchesAnyPrefix('/_wdt/token', '/assets', '/_wdt'));
        self::assertFalse($matcher->matchesAnyPrefix('/_wdtfoo', '/assets', '/_wdt'));
    }
}
