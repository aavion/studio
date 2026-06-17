<?php

declare(strict_types=1);

namespace App\Tests\Security\RateLimit;

use App\Security\RateLimit\RateLimitRequestSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpKernel\KernelEvents;

final class RateLimitRequestSubscriberTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function excludedPathCases(): iterable
    {
        yield 'live api root' => ['/api/live', true];
        yield 'live api child' => ['/api/live/status', true];
        yield 'live api sibling' => ['/api/live-status', false];
        yield 'assets child' => ['/assets/app.css', true];
        yield 'assets sibling' => ['/assets-preview', false];
        yield 'build child' => ['/build/app.js', true];
        yield 'build sibling' => ['/builder', false];
        yield 'profiler root' => ['/_profiler', true];
        yield 'profiler child' => ['/_profiler/123', true];
        yield 'profiler sibling' => ['/_profilerfoo', false];
        yield 'toolbar child' => ['/_wdt/123', true];
        yield 'toolbar sibling' => ['/_wdtfoo', false];
    }

    #[DataProvider('excludedPathCases')]
    public function testExcludedPathUsesSegmentBoundaries(string $path, bool $excluded): void
    {
        $subscriber = (new ReflectionClass(RateLimitRequestSubscriber::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(RateLimitRequestSubscriber::class, 'excludedPath');

        self::assertSame($excluded, $method->invoke($subscriber, $path));
    }

    public function testProbePriorityRunsBeforeResponseProducingGates(): void
    {
        $events = RateLimitRequestSubscriber::getSubscribedEvents()[KernelEvents::REQUEST];

        self::assertSame(['onKernelRequestProbe', 900], $events[0]);
        self::assertGreaterThan(768, $events[0][1]);
        self::assertGreaterThan(512, $events[0][1]);
        self::assertGreaterThan(256, $events[0][1]);
    }
}
