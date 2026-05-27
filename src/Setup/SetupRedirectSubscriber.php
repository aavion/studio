<?php

declare(strict_types=1);

namespace App\Setup;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class SetupRedirectSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private SetupCompletionMarker $completionMarker,
        private string $projectDir,
        private string $environment,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 512],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if ($this->completionMarker->isComplete($this->projectDir, $this->environment)) {
            return;
        }

        $path = $this->normalizePath($event->getRequest()->getPathInfo());

        if ($this->isBypassPath($path)) {
            return;
        }

        $event->setResponse(new RedirectResponse('/setup'));
    }

    private function isBypassPath(string $path): bool
    {
        foreach (['/setup', '/_profiler', '/_wdt', '/assets', '/build'] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return '/favicon.ico' === $path;
    }

    private function normalizePath(string $path): string
    {
        if ('/' === $path) {
            return '/';
        }

        return '/'.trim($path, '/');
    }
}
