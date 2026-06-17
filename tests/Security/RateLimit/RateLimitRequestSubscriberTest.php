<?php

declare(strict_types=1);

namespace App\Tests\Security\RateLimit;

use App\Security\Abuse\SuspiciousProbePathMatcher;
use App\Security\RateLimit\RateLimitRequestSubscriber;
use App\Security\RateLimit\RateLimitEnforcer;
use App\Security\RateLimit\RateLimitResponseRenderer;
use App\Setup\SetupCompletionMarker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class RateLimitRequestSubscriberTest extends TestCase
{
    private mixed $previousServerValue = null;
    private mixed $previousEnvValue = null;
    private mixed $previousPutenvValue = false;

    protected function setUp(): void
    {
        $this->previousServerValue = $_SERVER[SetupCompletionMarker::KEY] ?? null;
        $this->previousEnvValue = $_ENV[SetupCompletionMarker::KEY] ?? null;
        $this->previousPutenvValue = getenv(SetupCompletionMarker::KEY);
        $_SERVER[SetupCompletionMarker::KEY] = '1';
    }

    protected function tearDown(): void
    {
        unset($_SERVER[SetupCompletionMarker::KEY], $_ENV[SetupCompletionMarker::KEY]);

        if (null !== $this->previousServerValue) {
            $_SERVER[SetupCompletionMarker::KEY] = $this->previousServerValue;
        }

        if (null !== $this->previousEnvValue) {
            $_ENV[SetupCompletionMarker::KEY] = $this->previousEnvValue;
        }

        is_string($this->previousPutenvValue)
            ? putenv(SetupCompletionMarker::KEY.'='.$this->previousPutenvValue)
            : putenv(SetupCompletionMarker::KEY);
    }

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

    public function testProbeHookSkipsFullEnforcerForNonProbePaths(): void
    {
        $enforcer = (new ReflectionClass(RateLimitEnforcer::class))->newInstanceWithoutConstructor();
        $responses = (new ReflectionClass(RateLimitResponseRenderer::class))->newInstanceWithoutConstructor();
        $subscriber = new RateLimitRequestSubscriber(
            $enforcer,
            $responses,
            'prod',
            new SetupCompletionMarker(),
            dirname(__DIR__, 3),
            new SuspiciousProbePathMatcher(patterns: SuspiciousProbePathMatcher::DEFAULT_PATTERNS),
        );
        $event = new RequestEvent(
            new RateLimitRequestSubscriberTestKernel(),
            Request::create('/home'),
            HttpKernelInterface::MAIN_REQUEST,
        );

        $subscriber->onKernelRequestProbe($event);

        self::assertFalse($event->hasResponse());
    }

    public function testProbeHookUsesBareResponseBeforeSetupCompletion(): void
    {
        unset($_SERVER[SetupCompletionMarker::KEY], $_ENV[SetupCompletionMarker::KEY]);
        putenv(SetupCompletionMarker::KEY);
        $subscriber = $this->subscriberWithUninitializedEnforcer();
        $event = new RequestEvent(
            new RateLimitRequestSubscriberTestKernel(),
            Request::create('/.env'),
            HttpKernelInterface::MAIN_REQUEST,
        );

        $subscriber->onKernelRequestProbe($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_BAD_REQUEST, $event->getResponse()->getStatusCode());
        self::assertStringContainsString('no-store', (string) $event->getResponse()->headers->get('Cache-Control'));
    }

    public function testOrdinaryHookSkipsSetupWizardBeforeSetupCompletion(): void
    {
        unset($_SERVER[SetupCompletionMarker::KEY], $_ENV[SetupCompletionMarker::KEY]);
        putenv(SetupCompletionMarker::KEY);
        $subscriber = $this->subscriberWithUninitializedEnforcer();
        $event = new RequestEvent(
            new RateLimitRequestSubscriberTestKernel(),
            Request::create('/setup/review', 'POST', ['_setup_action' => 'apply']),
            HttpKernelInterface::MAIN_REQUEST,
        );

        $subscriber->onKernelRequestOrdinary($event);

        self::assertFalse($event->hasResponse());
    }

    private function subscriberWithUninitializedEnforcer(): RateLimitRequestSubscriber
    {
        return new RateLimitRequestSubscriber(
            (new ReflectionClass(RateLimitEnforcer::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(RateLimitResponseRenderer::class))->newInstanceWithoutConstructor(),
            'prod',
            new SetupCompletionMarker(),
            dirname(__DIR__, 3),
            new SuspiciousProbePathMatcher(patterns: SuspiciousProbePathMatcher::DEFAULT_PATTERNS),
        );
    }
}

final class RateLimitRequestSubscriberTestKernel implements HttpKernelInterface
{
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        return new Response();
    }
}
