<?php

declare(strict_types=1);

namespace App\Core\Log;

use App\Core\Statistics\AccessStatisticsRecorderInterface;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageReporterInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

final readonly class AccessLogSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private AccessLoggerInterface $accessLogger,
        private AccessStatisticsRecorderInterface $accessStatisticsRecorder,
        private AccessRequestMetadata $accessRequestMetadata,
        private ?MessageReporterInterface $messageReporter = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 255],
            KernelEvents::RESPONSE => ['onKernelResponse', -255],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $this->shouldSkip($event->getRequest()->getPathInfo())) {
            return;
        }

        $this->accessRequestMetadata->markStarted($event->getRequest());
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || $this->shouldSkip($event->getRequest()->getPathInfo())) {
            return;
        }

        try {
            $this->accessLogger->log($event->getRequest(), $event->getResponse());
        } catch (Throwable $error) {
            $this->reportAccessLogFailure($event, $error);
        }

        try {
            $this->accessStatisticsRecorder->record($event->getRequest(), $event->getResponse());
        } catch (Throwable) {
            return;
        }
    }

    private function shouldSkip(string $path): bool
    {
        return str_starts_with($path, '/_profiler')
            || str_starts_with($path, '/_wdt')
            || str_starts_with($path, '/assets/')
            || str_starts_with($path, '/build/');
    }

    private function reportAccessLogFailure(ResponseEvent $event, Throwable $error): void
    {
        try {
            $this->messageReporter?->report(Message::exception(
                MessageCode::E_OPERATION_FAILED,
                MessageKey::ACCESS_LOG_FAILED,
                [],
                [
                    'operation' => 'access.log',
                    'path' => $event->getRequest()->getPathInfo(),
                    'status' => $event->getResponse()->getStatusCode(),
                    'exception' => $error::class,
                    'message' => $error->getMessage(),
                ],
            ), [
                'operation' => 'access.log',
            ]);
        } catch (Throwable) {
            return;
        }
    }
}
