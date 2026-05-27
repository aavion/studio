<?php

declare(strict_types=1);

namespace App\Core\Log;

use App\Core\Statistics\AccessStatisticsRecorderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class AccessLogSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private AccessLoggerInterface $accessLogger,
        private AccessStatisticsRecorderInterface $accessStatisticsRecorder,
    )
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -255],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || $this->shouldSkip($event->getRequest()->getPathInfo())) {
            return;
        }

        $this->accessLogger->log($event->getRequest(), $event->getResponse());
        $this->accessStatisticsRecorder->record($event->getRequest(), $event->getResponse());
    }

    private function shouldSkip(string $path): bool
    {
        return str_starts_with($path, '/_profiler')
            || str_starts_with($path, '/_wdt')
            || str_starts_with($path, '/assets/')
            || str_starts_with($path, '/build/');
    }
}
