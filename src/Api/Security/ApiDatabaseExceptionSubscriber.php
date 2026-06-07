<?php

declare(strict_types=1);

namespace App\Api\Security;

use Doctrine\DBAL\Exception as DbalException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ApiDatabaseExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private ApiUnavailableResponder $unavailableResponder)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 0],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest() || !str_starts_with($event->getRequest()->getPathInfo(), '/api/v1')) {
            return;
        }

        $error = $event->getThrowable();
        if (!$error instanceof DbalException) {
            return;
        }

        $event->setResponse($this->unavailableResponder->databaseUnavailable($event->getRequest(), $error));
    }
}
