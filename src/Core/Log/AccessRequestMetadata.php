<?php

declare(strict_types=1);

namespace App\Core\Log;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AccessRequestMetadata
{
    public const REQUEST_ID_ATTRIBUTE = '_studio_access_request_id';
    public const STARTED_AT_ATTRIBUTE = '_studio_access_started_at';
    private const REDACTED_SEGMENT = '[redacted]';

    public function markStarted(Request $request): void
    {
        if (!$request->attributes->has(self::STARTED_AT_ATTRIBUTE)) {
            $request->attributes->set(self::STARTED_AT_ATTRIBUTE, microtime(true));
        }

        $this->requestId($request);
    }

    public function requestId(Request $request): string
    {
        $existing = $request->attributes->get(self::REQUEST_ID_ATTRIBUTE);

        if (is_string($existing) && '' !== $existing) {
            return $existing;
        }

        $requestId = $this->headerToken($request->headers->get('X-Request-ID'))
            ?? $this->headerToken($request->headers->get('X-Correlation-ID'))
            ?? bin2hex(random_bytes(16));
        $request->attributes->set(self::REQUEST_ID_ATTRIBUTE, $requestId);

        return $requestId;
    }

    public function durationMs(Request $request): ?int
    {
        $startedAt = $request->attributes->get(self::STARTED_AT_ATTRIBUTE);

        if (!is_float($startedAt) && !is_int($startedAt)) {
            return null;
        }

        return max(0, (int) round((microtime(true) - (float) $startedAt) * 1000));
    }

    public function surface(Request $request): string
    {
        $path = $request->getPathInfo();

        return match (true) {
            str_starts_with($path, '/admin') => 'admin',
            str_starts_with($path, '/editor') => 'editor',
            str_starts_with($path, '/api') => 'api',
            str_starts_with($path, '/setup') => 'setup',
            default => 'public',
        };
    }

    public function resolvedRoute(Request $request): string
    {
        $route = $request->attributes->get('_route');

        return is_string($route) && '' !== $route ? substr($route, 0, 190) : 'n/a';
    }

    public function sanitizedPath(Request $request): string
    {
        return $this->sanitizePathString($request->getPathInfo(), $this->sensitiveRouteValues($request));
    }

    public function referrer(Request $request): string
    {
        $referrer = trim((string) $request->headers->get('Referer', ''));

        if ('' === $referrer) {
            return 'n/a';
        }

        $parts = parse_url($referrer);

        if (!is_array($parts)) {
            return substr($referrer, 0, 1024);
        }

        $scheme = is_string($parts['scheme'] ?? null) ? $parts['scheme'].'://' : '';
        $host = is_string($parts['host'] ?? null) ? $parts['host'] : '';
        $path = is_string($parts['path'] ?? null) ? $this->sanitizePathString($parts['path']) : '';

        return substr(($host ? $scheme.$host : '').$path, 0, 1024) ?: 'n/a';
    }

    public function referrerHost(Request $request): string
    {
        $referrer = trim((string) $request->headers->get('Referer', ''));

        if ('' === $referrer) {
            return 'n/a';
        }

        $host = parse_url($referrer, PHP_URL_HOST);

        return is_string($host) && '' !== $host ? substr(strtolower($host), 0, 255) : 'n/a';
    }

    public function preferredLanguage(Request $request): string
    {
        $acceptLanguage = trim((string) $request->headers->get('Accept-Language', ''));

        if ('' === $acceptLanguage) {
            return 'n/a';
        }

        $first = strtolower(trim(explode(',', $acceptLanguage)[0] ?? ''));
        $first = preg_replace('/;q=.*$/', '', $first) ?? $first;

        return '' === $first ? 'n/a' : substr($first, 0, 20);
    }

    public function contentType(?string $value): string
    {
        $contentType = trim((string) $value);

        if ('' === $contentType) {
            return 'n/a';
        }

        return substr(strtolower(explode(';', $contentType)[0] ?? $contentType), 0, 120);
    }

    public function responseSize(Response $response): ?int
    {
        $contentLength = $response->headers->get('Content-Length');

        if (is_numeric($contentLength)) {
            return max(0, (int) $contentLength);
        }

        $content = $response->getContent();

        return is_string($content) ? strlen($content) : null;
    }

    /**
     * @return array{request_id: string, visitor_id: string, requested_path: string, resolved_route: string}
     */
    public function trace(Request $request, string $visitorId): array
    {
        return [
            'request_id' => $this->requestId($request),
            'visitor_id' => $visitorId,
            'requested_path' => $this->sanitizedPath($request),
            'resolved_route' => $this->resolvedRoute($request),
        ];
    }

    /**
     * @return list<string>
     */
    private function sensitiveRouteValues(Request $request): array
    {
        $values = [];

        foreach ($request->attributes->all() as $key => $value) {
            if (!$this->isSensitivePathKey((string) $key) || !is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ('' !== $value) {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param list<string> $sensitiveValues
     */
    private function sanitizePathString(string $path, array $sensitiveValues = []): string
    {
        if ('/' === $path || '' === $path) {
            return '/';
        }

        $segments = explode('/', trim($path, '/'));
        $sanitized = [];

        foreach ($segments as $index => $segment) {
            $decoded = rawurldecode($segment);
            $previous = $segments[$index - 1] ?? '';

            $sanitized[] = $this->isSensitivePathKey($previous) || in_array($decoded, $sensitiveValues, true) || in_array($segment, $sensitiveValues, true)
                ? self::REDACTED_SEGMENT
                : $segment;
        }

        return '/'.implode('/', $sanitized);
    }

    private function isSensitivePathKey(string $key): bool
    {
        $normalized = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $key));

        return 1 === preg_match('/(?:password|secret|token|credential|authorization|cookie|hmac|encrypted|api_key|private_key|code|signature|signed|session|csrf|nonce|reset|invite|invitation|(?:^|_)key(?:_|$))/', $normalized);
    }

    private function headerToken(?string $value): ?string
    {
        $value = trim((string) $value);

        if ('' === $value) {
            return null;
        }

        $token = preg_replace('/[^A-Za-z0-9._:-]/', '', $value) ?? '';

        return '' === $token ? null : substr($token, 0, 64);
    }
}
