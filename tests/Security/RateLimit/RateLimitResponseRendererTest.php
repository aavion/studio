<?php

declare(strict_types=1);

namespace App\Tests\Security\RateLimit;

use App\Core\Routing\PathScopeMatcher;
use App\Security\RateLimit\RateLimitResponseRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;

final class RateLimitResponseRendererTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function jsonSurfaceCases(): iterable
    {
        yield 'api v1' => ['/api/v1/status', true];
        yield 'cron root' => ['/cron', true];
        yield 'cron child' => ['/cron/run', true];
        yield 'cron lookalike content' => ['/cronjobs', false];
        yield 'browser content' => ['/docs', false];
    }

    #[DataProvider('jsonSurfaceCases')]
    public function testJsonSurfaceUsesPathBoundaries(string $path, bool $json): void
    {
        $renderer = (new ReflectionClass(RateLimitResponseRenderer::class))->newInstanceWithoutConstructor();
        $paths = new \ReflectionProperty(RateLimitResponseRenderer::class, 'paths');
        $paths->setValue($renderer, new PathScopeMatcher());
        $method = new \ReflectionMethod(RateLimitResponseRenderer::class, 'jsonSurface');

        self::assertSame($json, $method->invoke($renderer, Request::create($path)));
    }
}
