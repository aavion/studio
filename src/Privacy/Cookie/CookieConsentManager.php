<?php

declare(strict_types=1);

namespace App\Privacy\Cookie;

use App\Core\Statistics\VisitorIdGenerator;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class CookieConsentManager
{
    public const CONSENT_COOKIE_NAME = 'system_cookie_consent';
    private const TTL_SECONDS = 31_536_000;
    private const CLOCK_SKEW_SECONDS = 300;

    public function __construct(
        private CookieConsentRegistry $registry,
        private VisitorIdGenerator $visitorIdGenerator,
        private string $secret,
    ) {
    }

    public function csrfToken(Request $request): string
    {
        return hash_hmac(
            'sha256',
            'privacy_cookie_consent|'.$this->visitorIdGenerator->generate($request),
            $this->secret,
        );
    }

    public function validCsrfToken(Request $request, string $token): bool
    {
        return hash_equals($this->csrfToken($request), $token);
    }

    public function bannerRequired(Request $request): bool
    {
        return [] !== $this->registry->optionalDefinitions() && !$this->hasStoredConsent($request);
    }

    public function formRequired(Request $request): bool
    {
        return [] !== $this->registry->optionalDefinitions();
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
        $rejected = array_values(array_diff($allowedNames, $accepted));

        $response->headers->setCookie(Cookie::create(
            self::CONSENT_COOKIE_NAME,
            $this->encodeConsent([
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

        foreach ($this->registry->optionalDefinitions() as $definition) {
            if (!in_array($definition->name(), $rejected, true)) {
                continue;
            }

            $cookie = $definition->cookie();
            $response->headers->clearCookie(
                $cookie->getName(),
                $cookie->getPath(),
                $cookie->getDomain(),
                $cookie->isSecure(),
                $cookie->isHttpOnly(),
                $cookie->getSameSite(),
            );
        }
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

        $payload = $this->decodeConsent($value);
        if (null === $payload) {
            return null;
        }

        $createdAt = $payload['created_at'] ?? null;
        if (($payload['version'] ?? null) !== 1 || !is_int($createdAt)) {
            return null;
        }

        $now = time();
        if ($createdAt > $now + self::CLOCK_SKEW_SECONDS || $createdAt < $now - self::TTL_SECONDS) {
            return null;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodeConsent(array $payload): string
    {
        $body = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $body.'.'.$this->signature($body);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeConsent(string $value): ?array
    {
        $parts = explode('.', trim($value));
        if (2 !== count($parts) || !hash_equals($this->signature($parts[0]), $parts[1])) {
            return null;
        }

        $json = $this->base64UrlDecode($parts[0]);
        if (null === $json) {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function signature(string $body): string
    {
        return hash_hmac('sha256', 'privacy-cookie-consent|'.$body, $this->secret);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        if (1 !== preg_match('/\A[A-Za-z0-9_-]+\z/', $value)) {
            return null;
        }

        $base64 = strtr($value, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $decoded = base64_decode($base64, true);

        return false === $decoded ? null : $decoded;
    }
}
