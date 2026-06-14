<?php

declare(strict_types=1);

namespace App\Privacy\Cookie;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class CookieConsentManager
{
    public const CONSENT_COOKIE_NAME = 'studio_cookie_consent';
    private const TTL_SECONDS = 31_536_000;

    public function __construct(private CookieConsentRegistry $registry)
    {
    }

    public function bannerRequired(Request $request): bool
    {
        return [] !== $this->registry->optionalDefinitions() && !$this->hasStoredConsent($request);
    }

    public function allowed(Request $request, CookieConsentDefinition|string $definition): bool
    {
        $definition = is_string($definition) ? $this->registry->definition($definition) : $definition;
        if (!$definition instanceof CookieConsentDefinition) {
            return false;
        }

        if ($definition->isNecessary()) {
            return true;
        }

        return in_array($definition->name(), $this->acceptedOptionalNames($request), true);
    }

    /**
     * @param list<string> $acceptedOptionalNames
     */
    public function attachConsentCookie(Request $request, Response $response, array $acceptedOptionalNames): void
    {
        $allowedNames = array_map(
            static fn (CookieConsentDefinition $definition): string => $definition->name(),
            $this->registry->optionalDefinitions(),
        );
        $accepted = array_values(array_intersect($allowedNames, array_unique($acceptedOptionalNames)));

        $response->headers->setCookie(Cookie::create(
            self::CONSENT_COOKIE_NAME,
            $this->encode([
                'accepted' => $accepted,
                'created_at' => time(),
                'version' => 1,
            ]),
            time() + self::TTL_SECONDS,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            Cookie::SAMESITE_LAX,
        ));
    }

    public function defaultOptionalSelected(Request $request): bool
    {
        return '1' !== (string) $request->headers->get('DNT', '')
            && '1' !== (string) $request->headers->get('Sec-GPC', '');
    }

    /**
     * @return list<string>
     */
    public function selectedOptionalNames(Request $request): array
    {
        if ($this->hasStoredConsent($request)) {
            return $this->acceptedOptionalNames($request);
        }

        if (!$this->defaultOptionalSelected($request)) {
            return [];
        }

        return array_map(
            static fn (CookieConsentDefinition $definition): string => $definition->name(),
            $this->registry->optionalDefinitions(),
        );
    }

    /**
     * @return list<string>
     */
    public function acceptedOptionalNames(Request $request): array
    {
        $payload = $this->storedConsent($request);
        $accepted = $payload['accepted'] ?? [];

        return is_array($accepted)
            ? array_values(array_filter(array_map('strval', $accepted), 'strlen'))
            : [];
    }

    private function hasStoredConsent(Request $request): bool
    {
        return null !== $this->storedConsent($request);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function storedConsent(Request $request): ?array
    {
        $value = (string) $request->cookies->get(self::CONSENT_COOKIE_NAME, '');
        if ('' === $value) {
            return null;
        }

        $decoded = json_decode(base64_decode($value, true) ?: '', true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return base64_encode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
