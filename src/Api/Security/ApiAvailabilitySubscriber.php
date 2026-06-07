<?php

declare(strict_types=1);

namespace App\Api\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

final readonly class ApiAvailabilitySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ApiAvailabilityCheckerInterface $availabilityChecker,
        private ApiUnavailableResponder $unavailableResponder,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 768],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !str_starts_with($event->getRequest()->getPathInfo(), '/api/v1')) {
            return;
        }

        try {
            if (!$this->availabilityChecker->isAvailable()) {
                $event->setResponse($this->unavailableResponder->setupIncomplete($event->getRequest()));
            }
        } catch (Throwable $error) {
            $event->setResponse($this->unavailableResponder->databaseUnavailable($event->getRequest(), $error));
        }
    }
}
