<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

use App\Security\Abuse\SuspiciousProbePathMatcher;
use App\Setup\SetupCompletionMarker;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class RateLimitRequestSubscriber implements EventSubscriberInterface
{
    private SuspiciousProbePathMatcher $probePathMatcher;

    public function __construct(
        private RateLimitEnforcer $enforcer,
        private RateLimitResponseRenderer $responses,
        private string $environment,
        private SetupCompletionMarker $setupCompletionMarker,
        private string $projectDir,
        ?SuspiciousProbePathMatcher $probePathMatcher = null,
    ) {
        $this->probePathMatcher = $probePathMatcher ?? new SuspiciousProbePathMatcher(patterns: SuspiciousProbePathMatcher::DEFAULT_PATTERNS);
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
            $event->setResponse($this->bareSuspiciousProbeResponse());

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
        if (!$this->enabledForRequest($request->headers->get('X-Rate-Limit-Testing')) || $this->excludedPath($request->getPathInfo())) {
            return;
        }

        if (!$this->setupCompleted()) {
            return;
        }

        $this->apply($event, RateLimitEnforcementStage::Ordinary);
    }

    private function bareSuspiciousProbeResponse(): Response
    {
        return new Response('', Response::HTTP_BAD_REQUEST, ['Cache-Control' => 'no-store']);
    }

    private function apply(RequestEvent $event, RateLimitEnforcementStage $stage): void
    {
        $request = $event->getRequest();
        $result = $this->enforcer->check($request, $stage);
        if ($result->isAllowed()) {
            return;
        }

        $event->setResponse($result->suspiciousProbe()
            ? $this->responses->suspiciousProbe($request)
            : $this->responses->tooManyRequests($request, $result));
    }

    private function excludedPath(string $path): bool
    {
        return $this->pathMatchesPrefix($path, '/api/live')
            || $this->pathMatchesPrefix($path, '/assets')
            || $this->pathMatchesPrefix($path, '/build')
            || $this->pathMatchesPrefix($path, '/_profiler')
            || $this->pathMatchesPrefix($path, '/_wdt')
            || in_array($path, ['/favicon.ico', '/robots.txt'], true);
    }

    private function pathMatchesPrefix(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, $prefix.'/');
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
