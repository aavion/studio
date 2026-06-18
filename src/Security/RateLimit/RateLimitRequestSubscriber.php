<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

use App\Core\Routing\PathScopeMatcher;
use App\Core\Routing\IgnorableRequestPathMatcher;
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
    private PathScopeMatcher $paths;
    private IgnorableRequestPathMatcher $ignorablePaths;

    public function __construct(
        private RateLimitEnforcer $enforcer,
        private RateLimitResponseRenderer $responses,
        private string $environment,
        private SetupCompletionMarker $setupCompletionMarker,
        private string $projectDir,
        ?SuspiciousProbePathMatcher $probePathMatcher = null,
        ?PathScopeMatcher $paths = null,
        ?IgnorableRequestPathMatcher $ignorablePaths = null,
    ) {
        $this->probePathMatcher = $probePathMatcher ?? new SuspiciousProbePathMatcher(patterns: SuspiciousProbePathMatcher::DEFAULT_PATTERNS);
        $this->paths = $paths ?? new PathScopeMatcher();
        $this->ignorablePaths = $ignorablePaths ?? new IgnorableRequestPathMatcher($this->paths);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [
                ['onKernelRequestProbe', 4096],
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

        $this->enforcer->check($request, RateLimitEnforcementStage::SuspiciousProbe);

        $event->setResponse($this->responses->invalidRequest($request));
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
        return $this->paths->matchesAnyPrefix($request->getPathInfo(), '/api/live')
            || $this->ignorablePaths->matches($request->getPathInfo());
    }

    private function setupApplyRequest(Request $request): bool
    {
        return 'POST' === strtoupper($request->getMethod())
            && $this->paths->matchesExactSegments($request->getPathInfo(), 'setup', 'review')
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
