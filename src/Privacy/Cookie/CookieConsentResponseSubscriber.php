<?php

declare(strict_types=1);

namespace App\Privacy\Cookie;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class CookieConsentResponseSubscriber implements EventSubscriberInterface
{
    private const RESPONSE_PRIORITY = -4096;

    public function __construct(
        private CookieConsentRegistry $registry,
        private CookieConsentManager $consent,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['filterCookies', self::RESPONSE_PRIORITY],
        ];
    }

    public function filterCookies(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        $request = $event->getRequest();

        foreach ($response->headers->getCookies() as $cookie) {
            if (0 !== $cookie->getExpiresTime() && $cookie->getExpiresTime() <= time()) {
                continue;
            }

            $definition = $this->registry->definition($cookie->getName());
            if (!$definition instanceof CookieConsentDefinition) {
                continue;
            }

            if ($definition->matchesResponseCookie($cookie) && $this->consent->allowed($request, $definition)) {
                continue;
            }

            $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
        }
    }
}
