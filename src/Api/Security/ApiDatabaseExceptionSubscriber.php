<?php

declare(strict_types=1);

namespace App\Api\Security;

use Doctrine\DBAL\Exception as DbalException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ApiDatabaseExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ApiUnavailableResponder $unavailableResponder,
        private ApiRequestMethodPolicy $methodPolicy = new ApiRequestMethodPolicy(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 0],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->methodPolicy->isApiV1Request($event->getRequest())) {
            return;
        }

        $error = $event->getThrowable();
        if (!$error instanceof DbalException) {
            return;
        }

        $event->setResponse($this->unavailableResponder->databaseUnavailable($event->getRequest(), $error));
    }
}
