<?php

declare(strict_types=1);

namespace App\Api\Http;

use App\Core\Log\AccessRequestMetadata;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ApiTraceHeaderSubscriber implements EventSubscriberInterface
{
    public function __construct(private AccessRequestMetadata $accessRequestMetadata)
    {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', 64],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !str_starts_with($event->getRequest()->getPathInfo(), '/api/v1')) {
            return;
        }

        $response = $event->getResponse();
        $response->headers->set('X-Request-ID', $this->accessRequestMetadata->requestId($event->getRequest()));

        $correlationId = $this->accessRequestMetadata->correlationId($event->getRequest());
        if ('n/a' !== $correlationId) {
            $response->headers->set('X-Correlation-ID', $correlationId);
        }
    }
}
