<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

use App\Core\Routing\RequestPathResolver;
use App\Security\Abuse\SuspiciousProbePathMatcher;
use App\Setup\SetupCompletionMarker;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class RateLimitRequestSubscriber implements EventSubscriberInterface
{
    private SuspiciousProbePathMatcher $probePathMatcher;
    private RequestPathResolver $paths;

    public function __construct(
        private RateLimitEnforcer $enforcer,
        private RateLimitResponseRenderer $responses,
        private string $environment,
        private SetupCompletionMarker $setupCompletionMarker,
        private string $projectDir,
        ?SuspiciousProbePathMatcher $probePathMatcher = null,
        ?RequestPathResolver $paths = null,
    ) {
        $this->probePathMatcher = $probePathMatcher ?? new SuspiciousProbePathMatcher(patterns: SuspiciousProbePathMatcher::DEFAULT_PATTERNS);
        $this->paths = $paths ?? new RequestPathResolver();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [
                ['onKernelRequestProbe', 900],
                ['onKernelRequestOrdinary', 3],
            ],
        ];
    }

    public function onKernelRequestProbe(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->enabledForRequest($request->headers->get('X-Rate-Limit-Testing'))) {
            return;
        }

        if (!$this->probePathMatcher->isProbe($request->getPathInfo())) {
            return;
        }

        if (!$this->setupCompleted()) {
            $event->setResponse($this->responses->bare($request, Response::HTTP_BAD_REQUEST));

            return;
        }

        $this->apply($event, RateLimitEnforcementStage::SuspiciousProbe);
    }

    public function onKernelRequestOrdinary(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $event->hasResponse()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->enabledForRequest($request->headers->get('X-Rate-Limit-Testing')) || $this->excludedRequest($request)) {
            return;
        }

        $setupCompleted = $this->setupCompleted();
        if (!$setupCompleted && !$this->setupApplyRequest($request)) {
            return;
        }

        $this->apply($event, RateLimitEnforcementStage::Ordinary, bareResponse: !$setupCompleted);
    }

    private function apply(RequestEvent $event, RateLimitEnforcementStage $stage, bool $bareResponse = false): void
    {
        $request = $event->getRequest();
        $result = $this->enforcer->check($request, $stage);
        if ($result->isAllowed()) {
            return;
        }

        if ($bareResponse) {
            $event->setResponse($this->responses->bare($request, Response::HTTP_TOO_MANY_REQUESTS, $result->retryAfterSeconds()));

            return;
        }

        $event->setResponse($result->suspiciousProbe()
            ? $this->responses->suspiciousProbe($request)
            : $this->responses->tooManyRequests($request, $result));
    }

    private function excludedRequest(Request $request): bool
    {
        return $this->paths->matchesAny($request, ['api', 'live'], ['assets'], ['build'], ['_profiler'], ['_wdt'])
            || in_array($request->getPathInfo(), ['/favicon.ico', '/robots.txt'], true);
    }

    private function setupApplyRequest(Request $request): bool
    {
        return 'POST' === strtoupper($request->getMethod())
            && $this->paths->matchesExact($request, 'setup', 'review')
            && 'apply' === (string) $request->request->get('_setup_action', '');
    }

    private function enabledForRequest(?string $testOptIn): bool
    {
        return 'test' !== $this->environment || '1' === $testOptIn;
    }

    private function setupCompleted(): bool
    {
        return $this->setupCompletionMarker->isComplete($this->projectDir, $this->environment);
    }
}
