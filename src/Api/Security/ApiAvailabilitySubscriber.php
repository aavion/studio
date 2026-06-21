<?php

declare(strict_types=1);

namespace App\Api\Security;

use App\Api\ApiFeaturePolicy;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

final readonly class ApiAvailabilitySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ApiAvailabilityCheckerInterface $availabilityChecker,
        private ApiUnavailableResponder $unavailableResponder,
        private ApiFeaturePolicy $apiFeaturePolicy,
        private ApiRequestMethodPolicy $methodPolicy = new ApiRequestMethodPolicy(),
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
        if (!$event->isMainRequest() || !$this->methodPolicy->isApiV1Request($event->getRequest())) {
            return;
        }

        try {
            if (!$this->apiFeaturePolicy->isEnabled()) {
                $event->setResponse($this->unavailableResponder->apiDisabled($event->getRequest()));

                return;
            }

            if (!$this->availabilityChecker->isAvailable()) {
                $event->setResponse($this->unavailableResponder->setupIncomplete($event->getRequest()));
            }
        } catch (Throwable $error) {
            $event->setResponse($this->unavailableResponder->databaseUnavailable($event->getRequest(), $error));
        }
    }
}
