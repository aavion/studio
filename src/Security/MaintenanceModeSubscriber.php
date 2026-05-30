<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Access\AccessLevel;
use App\Localization\TranslationLanguageCatalog;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final readonly class MaintenanceModeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private bool $maintenanceEnabled,
        private TokenStorageInterface $tokenStorage,
        private TranslationLanguageCatalog $languageCatalog,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 256],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$this->maintenanceEnabled || !$event->isMainRequest()) {
            return;
        }

        $path = $this->normalizePath($event->getRequest()->getPathInfo());

        if ($this->isBypassPath($path) || $this->hasMaintenanceBypassAccess()) {
            return;
        }

        throw new ServiceUnavailableHttpException(message: 'Application maintenance mode is active.');
    }

    private function hasMaintenanceBypassAccess(): bool
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        if (!$user instanceof AccessLevelAwareUserInterface) {
            return false;
        }

        return $user->accessLevel() >= AccessLevel::ADMIN;
    }

    private function isBypassPath(string $path): bool
    {
        foreach (['/_profiler', '/_wdt', '/assets', '/build'] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        if (in_array($path, ['/favicon.ico', '/admin', '/user/login'], true)) {
            return true;
        }

        if (str_starts_with($path, '/admin/')) {
            return true;
        }

        foreach ($this->languageCatalog->availableLanguages() as $language) {
            if ($path === '/'.$language.'/user/login') {
                return true;
            }
        }

        return false;
    }

    private function normalizePath(string $path): string
    {
        if ('/' === $path) {
            return '/';
        }

        return '/'.trim($path, '/');
    }
}
