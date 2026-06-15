<?php

declare(strict_types=1);

namespace App\Privacy\Cookie;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ConsentCookieJar
{
    public function __construct(private CookieConsentManager $consent)
    {
    }

    public function get(Request $request, CookieConsentDefinition|string $definition): ?string
    {
        if (!$this->consent->allowed($request, $definition)) {
            return null;
        }

        $name = $definition instanceof CookieConsentDefinition ? $definition->name() : $definition;
        $value = $request->cookies->get($name);

        return is_scalar($value) ? (string) $value : null;
    }

    public function set(Request $request, Response $response, CookieConsentDefinition $definition, ?Cookie $cookie = null): bool
    {
        if (!$this->consent->allowed($request, $definition)) {
            return false;
        }

        $cookie ??= $definition->cookie();
        if (!$definition->matchesCookieIdentity($cookie)) {
            return false;
        }

        $response->headers->setCookie($cookie);

        return true;
    }
}
