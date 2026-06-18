<?php

declare(strict_types=1);

namespace App\Tests\Core\Routing;

use App\Core\Routing\IgnorableRequestPathMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IgnorableRequestPathMatcherTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function pathCases(): iterable
    {
        yield 'assets root' => ['/assets', true];
        yield 'assets child' => ['/assets/app.css', true];
        yield 'assets sibling' => ['/assets-preview', false];
        yield 'build root' => ['/build', true];
        yield 'profiler child' => ['/_profiler/123', true];
        yield 'toolbar sibling' => ['/_wdtfoo', false];
        yield 'favicon' => ['/favicon.ico', true];
        yield 'robots' => ['/robots.txt', true];
        yield 'touch icon' => ['/apple-touch-icon.png', true];
        yield 'site manifest' => ['/site.webmanifest', true];
        yield 'sitemap' => ['/sitemap.xml', true];
        yield 'well-known security' => ['/.well-known/security.txt', true];
        yield 'well-known webfinger' => ['/.well-known/webfinger', true];
        yield 'well-known unknown child' => ['/.well-known/random-scanner', false];
        yield 'application route' => ['/missing', false];
    }

    #[DataProvider('pathCases')]
    public function testItMatchesIgnorableStaticAndToolingPaths(string $path, bool $expected): void
    {
        self::assertSame($expected, (new IgnorableRequestPathMatcher())->matches($path));
    }
}
