<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class RateLimitRequestSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RateLimitEnforcer $enforcer,
        private RateLimitResponseRenderer $responses,
        private string $environment,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 12],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $event->hasResponse()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->enabledForRequest($request->headers->get('X-Rate-Limit-Testing')) || $this->excludedPath($request->getPathInfo())) {
            return;
        }

        $result = $this->enforcer->check($request);
        if ($result->isAllowed()) {
            return;
        }

        $event->setResponse($result->suspiciousProbe()
            ? $this->responses->suspiciousProbe($request)
            : $this->responses->tooManyRequests($request, $result));
    }

    private function excludedPath(string $path): bool
    {
        return str_starts_with($path, '/api/live/')
            || str_starts_with($path, '/assets/')
            || str_starts_with($path, '/build/')
            || str_starts_with($path, '/_profiler')
            || str_starts_with($path, '/_wdt')
            || in_array($path, ['/favicon.ico', '/robots.txt'], true);
    }

    private function enabledForRequest(?string $testOptIn): bool
    {
        return 'test' !== $this->environment || '1' === $testOptIn;
    }
}
