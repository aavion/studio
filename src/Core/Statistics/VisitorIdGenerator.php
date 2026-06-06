<?php

declare(strict_types=1);

namespace App\Core\Statistics;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class VisitorIdGenerator
{
    public const COOKIE_NAME = 'system_visitor';
    private const ATTRIBUTE_PENDING_TOKEN = '_system_visitor_pending_token';
    private const ATTRIBUTE_ID = '_system_visitor_id';
    private const PLACEHOLDER = 'n/a';
    private const COOKIE_VERSION = 'v1';
    private const COOKIE_LIFETIME_SECONDS = 2_592_000;
    private const VISITOR_ID_BYTES = 16;

    public function __construct(
        private string $secret,
        private ?VisitorIdentityStoreInterface $identityStore = null,
    ) {
    }

    public function generate(Request $request): string
    {
        $existing = $request->attributes->get(self::ATTRIBUTE_ID);

        if (is_string($existing) && '' !== $existing) {
            return $existing;
        }

        $visitorId = $this->resolveVisitorId($request);
        $request->attributes->set(self::ATTRIBUTE_ID, $visitorId);

        return $visitorId;
    }

    public function attachCookie(Request $request, Response $response): void
    {
        $token = $this->cookieToken($request) ?? $this->pendingToken($request);
        $cookie = Cookie::create(
            self::COOKIE_NAME,
            $this->packCookieValue($token),
            time() + self::COOKIE_LIFETIME_SECONDS,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            Cookie::SAMESITE_LAX,
        );

        $response->headers->setCookie($cookie);
    }

    public function sourceIp(Request $request): string
    {
        return $request->getClientIp() ?? self::PLACEHOLDER;
    }

    public function clientIp(Request $request): string
    {
        return $request->getClientIp() ?? self::PLACEHOLDER;
    }

    public function proxyClientIp(Request $request): string
    {
        return $this->proxyIpChain($request)[0] ?? self::PLACEHOLDER;
    }

    /**
     * @return list<string>
     */
    public function proxyIpChain(Request $request): array
    {
        $candidates = [
            ...$this->splitHeader($request->headers->get('X-Forwarded-For')),
            ...$this->splitHeader($request->headers->get('Forwarded')),
            ...$this->splitHeader($request->headers->get('X-Real-IP')),
            ...$this->splitHeader($request->headers->get('CF-Connecting-IP')),
            ...$this->splitHeader($request->headers->get('True-Client-IP')),
        ];
        $ips = [];

        foreach ($candidates as $candidate) {
            $ip = trim($candidate, " \t\n\r\0\x0B\"[]");

            if (str_contains($ip, '=')) {
                $parts = [];
                parse_str(str_replace(';', '&', $ip), $parts);
                $ip = is_string($parts['for'] ?? null) ? trim($parts['for'], " \t\n\r\0\x0B\"[]") : $ip;
            }

            if (filter_var($ip, FILTER_VALIDATE_IP) && !in_array($ip, $ips, true)) {
                $ips[] = $ip;
            }
        }

        return $ips;
    }

    public function normalizedUserAgent(Request $request): string
    {
        $userAgent = trim((string) $request->headers->get('User-Agent', self::PLACEHOLDER));

        return '' === $userAgent ? self::PLACEHOLDER : substr(strtolower($userAgent), 0, 500);
    }

    /**
     * @return list<string>
     */
    private function splitHeader(?string $value): array
    {
        if (null === $value || '' === trim($value)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/,/', $value) ?: [])));
    }

    private function cookieToken(Request $request): ?string
    {
        return $this->unpackCookieValue((string) $request->cookies->get(self::COOKIE_NAME, ''));
    }

    private function pendingToken(Request $request): string
    {
        $token = $request->attributes->get(self::ATTRIBUTE_PENDING_TOKEN);

        if (is_string($token) && '' !== $token) {
            return $token;
        }

        $token = $this->generateToken();
        $request->attributes->set(self::ATTRIBUTE_PENDING_TOKEN, $token);

        return $token;
    }

    private function resolveVisitorId(Request $request): string
    {
        $cookieToken = $this->cookieToken($request);

        if (null === $this->identityStore) {
            return null === $cookieToken ? $this->fallbackVisitorId($request) : $this->visitorId($cookieToken);
        }

        if (null !== $cookieToken) {
            $visitorId = $this->identityStore->resolve(
                $this->cookieHash($cookieToken),
                $this->fallbackHash($request),
                null,
                $this->visitorId($cookieToken),
            );

            return $visitorId ?? $this->visitorId($cookieToken);
        }

        $pendingToken = $this->pendingToken($request);
        $visitorId = $this->identityStore->resolve(
            null,
            $this->fallbackHash($request),
            $this->cookieHash($pendingToken),
            $this->visitorId($pendingToken),
        );

        return $visitorId ?? $this->fallbackVisitorId($request);
    }

    private function generateToken(): string
    {
        return $this->base64Url(random_bytes(32));
    }

    private function fallbackVisitorId(Request $request): string
    {
        return $this->base64Url(substr(hash_hmac(
            'sha256',
            implode('|', [
                'visitor-fallback-id',
                $this->sourceIp($request),
                $this->normalizedUserAgent($request),
            ]),
            $this->secret,
            true,
        ), 0, self::VISITOR_ID_BYTES));
    }

    private function visitorId(string $token): string
    {
        return $this->base64Url(substr(hash_hmac('sha256', 'visitor-id|'.$token, $this->secret, true), 0, self::VISITOR_ID_BYTES));
    }

    private function cookieHash(string $token): string
    {
        return $this->base64Url(hash_hmac('sha256', 'visitor-cookie|'.$token, $this->secret, true));
    }

    private function fallbackHash(Request $request): string
    {
        return $this->base64Url(hash_hmac(
            'sha256',
            implode('|', [
                'visitor-fallback',
                $this->sourceIp($request),
                $this->normalizedUserAgent($request),
            ]),
            $this->secret,
            true,
        ));
    }

    private function packCookieValue(string $token): string
    {
        return self::COOKIE_VERSION.'.'.$token.'.'.$this->signature($token);
    }

    private function unpackCookieValue(string $value): ?string
    {
        $parts = explode('.', trim($value));

        if (3 !== count($parts) || self::COOKIE_VERSION !== $parts[0]) {
            return null;
        }

        $token = $parts[1];

        if (1 !== preg_match('/\A[A-Za-z0-9_-]{32,128}\z/', $token)) {
            return null;
        }

        return hash_equals($this->signature($token), $parts[2]) ? $token : null;
    }

    private function signature(string $token): string
    {
        return hash_hmac('sha256', self::COOKIE_VERSION.'|'.$token, $this->secret);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
