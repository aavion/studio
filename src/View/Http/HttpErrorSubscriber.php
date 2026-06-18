<?php

declare(strict_types=1);

namespace App\View\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class HttpErrorSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private HttpErrorRenderer $renderer,
        private bool $debug = false,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', -128],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if ($this->debug) {
            return;
        }

        $exception = $event->getThrowable();

        if (!$exception instanceof HttpExceptionInterface) {
            return;
        }

        $response = $this->renderer->resolve($exception->getStatusCode(), $event->getRequest(), exception: $exception);
        $response->headers->add($exception->getHeaders());
        $event->setResponse($response);
    }
}
