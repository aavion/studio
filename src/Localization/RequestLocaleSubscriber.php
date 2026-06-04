<?php

declare(strict_types=1);

namespace App\Localization;

use App\Content\Routing\ContentRouteLocalization;
use App\Entity\UserAccount;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Translation\LocaleSwitcher;

final readonly class RequestLocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ContentRouteLocalization $localization,
        private TokenStorageInterface $tokenStorage,
        private LocaleSwitcher $localeSwitcher,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 7],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $locale = $this->userLocale() ?? $this->sessionLocale($event) ?? $this->localization->defaultLanguage();

        if (!in_array($locale, $this->localization->availableLanguages(), true)) {
            return;
        }

        $request = $event->getRequest();
        $request->setLocale($locale);
        $this->localeSwitcher->setLocale($locale);

        try {
            $request->getSession()->set('_locale', $locale);
        } catch (SessionNotFoundException) {
        }
    }

    private function userLocale(): ?string
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        if (!$user instanceof UserAccount) {
            return null;
        }

        $language = $user->settings()['language'] ?? null;

        if (!is_string($language) || '' === trim($language) || 'default' === $language) {
            return null;
        }

        return $language;
    }

    private function sessionLocale(RequestEvent $event): ?string
    {
        $request = $event->getRequest();

        try {
            $locale = $request->getSession()->get('_locale');
        } catch (SessionNotFoundException) {
            return null;
        }

        return is_string($locale) && '' !== trim($locale) ? $locale : null;
    }
}
