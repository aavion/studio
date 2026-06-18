<?php

declare(strict_types=1);

namespace App\Core\Log;

use App\Core\Access\AccessMessageKey;
use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Message\MessageReporterInterface;
use App\Core\Routing\IgnorableRequestPathMatcher;
use App\Core\Statistics\AccessStatisticsRecorderInterface;
use App\Core\Statistics\VisitorIdGenerator;
use App\Database\DatabaseReadyState;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

final readonly class AccessLogSubscriber implements EventSubscriberInterface
{
    private IgnorableRequestPathMatcher $ignorablePaths;

    public function __construct(
        private AccessLoggerInterface $accessLogger,
        private AccessStatisticsRecorderInterface $accessStatisticsRecorder,
        private AccessRequestMetadata $accessRequestMetadata,
        private VisitorIdGenerator $visitorIdGenerator,
        private ?MessageReporterInterface $messageReporter = null,
        private ?DatabaseReadyState $databaseReadyState = null,
        ?IgnorableRequestPathMatcher $ignorablePaths = null,
    ) {
        $this->ignorablePaths = $ignorablePaths ?? new IgnorableRequestPathMatcher();
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
        if (!$event->isMainRequest() || $this->shouldSkipAccessLog($event->getRequest())) {
            return;
        }

        $this->accessRequestMetadata->markStarted($event->getRequest());
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || $this->shouldSkipAccessLog($event->getRequest())) {
            return;
        }

        $this->visitorIdGenerator->attachCookie($event->getRequest(), $event->getResponse());

        try {
            $this->accessLogger->log($event->getRequest(), $event->getResponse());
        } catch (Throwable $error) {
            $this->reportAccessLogFailure($event, $error);
        }

        if (!$this->shouldSkipStatistics($event->getRequest()->getPathInfo())) {
            try {
                $this->accessStatisticsRecorder->record($event->getRequest(), $event->getResponse());
            } catch (Throwable) {
                return;
            }
        }
    }

    private function shouldSkipAccessLog(Request $request): bool
    {
        return !$request->attributes->getBoolean(AccessRequestMetadata::FORCE_ACCESS_LOG_ATTRIBUTE)
            && $this->ignorablePaths->matches($request->getPathInfo());
    }

    private function shouldSkipStatistics(string $path): bool
    {
        return $this->databaseIsNotReady()
            || str_starts_with($path, '/setup')
            || $this->ignorablePaths->matches($path);
    }

    private function databaseIsNotReady(): bool
    {
        return null !== $this->databaseReadyState && !$this->databaseReadyState->isReady();
    }

    private function reportAccessLogFailure(ResponseEvent $event, Throwable $error): void
    {
        try {
            $this->messageReporter?->report(Message::exception(
                CommonMessageCode::E_OPERATION_FAILED,
                AccessMessageKey::ACCESS_LOG_FAILED,
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
