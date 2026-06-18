<?php

declare(strict_types=1);

namespace App\Api\Security;

use App\Api\Http\ApiRequestContext;
use App\Core\Access\AccessLevel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ApiMaintenanceModeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private bool $maintenanceEnabled,
        private ApiUnavailableResponder $unavailableResponder,
        private ApiRequestMethodPolicy $methodPolicy = new ApiRequestMethodPolicy(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 2],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->maintenanceEnabled || !$event->isMainRequest() || $event->hasResponse() || !$this->methodPolicy->isApiV1Request($request)) {
            return;
        }

        $context = ApiRequestContext::fromRequest($request);
        if (null !== $context && $context->actor()->accessLevel() >= AccessLevel::ADMIN) {
            return;
        }

        $event->setResponse($this->unavailableResponder->maintenance($request));
    }
}
