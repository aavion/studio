<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Privacy\Cookie\CookieConsentDefinition;
use App\Privacy\Cookie\CookieConsentManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;
use WeakMap;

final class ExtensionCookieFacade implements EventSubscriberInterface
{
    private const MAX_VALUE_BYTES = 4096;
    private const MAX_TTL_SECONDS = 31_536_000;

    /**
     * @var WeakMap<Request, list<Cookie>>
     */
    private WeakMap $queued;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly CookieConsentManager $consent,
        private readonly ExtensionRuntimeContributionRegistry $runtimeContributions,
    ) {
        $this->queued = new WeakMap();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['flushQueuedCookies', -4095],
        ];
    }

    public function get(string $extensionName, string $name): ?string
    {
        $request = $this->request($extensionName, $name);
        $definition = $this->definition($extensionName, $name);
        if (!$request instanceof Request || !$definition instanceof CookieConsentDefinition || !$this->consent->allowed($request, $definition)) {
            return null;
        }

        $value = $request->cookies->get($definition->name());

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function set(string $extensionName, string $name, string $value, array $options = []): bool
    {
        if (strlen($value) > self::MAX_VALUE_BYTES) {
            return false;
        }

        $request = $this->request($extensionName, $name);
        $definition = $this->definition($extensionName, $name);
        if (!$request instanceof Request || !$definition instanceof CookieConsentDefinition || !$this->consent->allowed($request, $definition)) {
            return false;
        }

        $this->queue($request, $this->cookie($definition, $value, $options));

        return true;
    }

    public function delete(string $extensionName, string $name): bool
    {
        $request = $this->request($extensionName, $name);
        $definition = $this->definition($extensionName, $name);
        if (!$request instanceof Request || !$definition instanceof CookieConsentDefinition) {
            return false;
        }

        $base = $definition->cookie();
        $this->queue($request, Cookie::create(
            $base->getName(),
            '',
            1,
            $base->getPath(),
            $base->getDomain(),
            $base->isSecure(),
            $base->isHttpOnly(),
            false,
            $base->getSameSite(),
        ));

        return true;
    }

    public function flushQueuedCookies(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $cookies = $this->queued[$request] ?? [];
        if ([] === $cookies) {
            return;
        }

        foreach ($cookies as $cookie) {
            $event->getResponse()->headers->setCookie($cookie);
        }

        unset($this->queued[$request]);
    }

    private function request(string $extensionName, string $name): ?Request
    {
        if (!ExtensionManifestSpec::isValidSlug($extensionName) || '' === trim($name)) {
            return null;
        }

        return $this->requestStack->getCurrentRequest();
    }

    private function definition(string $extensionName, string $name): ?CookieConsentDefinition
    {
        try {
            return $this->runtimeContributions->cookieConsentDefinitionForExtension($extensionName, trim($name));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function cookie(CookieConsentDefinition $definition, string $value, array $options): Cookie
    {
        $base = $definition->cookie();
        $expires = $this->expires($options);

        return Cookie::create(
            $base->getName(),
            $value,
            $expires,
            $base->getPath(),
            $base->getDomain(),
            $base->isSecure(),
            $base->isHttpOnly(),
            false,
            $base->getSameSite(),
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function expires(array $options): int
    {
        $expires = $options['expires'] ?? null;
        if (is_int($expires) && $expires > time()) {
            return $expires;
        }

        $ttl = $options['ttl_seconds'] ?? $options['max_age'] ?? null;
        if (!is_int($ttl) && !(is_string($ttl) && ctype_digit($ttl))) {
            return 0;
        }

        $ttl = max(1, min(self::MAX_TTL_SECONDS, (int) $ttl));

        return time() + $ttl;
    }

    private function queue(Request $request, Cookie $cookie): void
    {
        $cookies = $this->queued[$request] ?? [];
        $cookies[] = $cookie;
        $this->queued[$request] = $cookies;
    }
}
