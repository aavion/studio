<?php

declare(strict_types=1);

namespace App\Core\Log;

use App\Content\Routing\ContentRouteLocalization;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AccessRequestMetadata
{
    public const REQUEST_ID_ATTRIBUTE = '_access_request_id';
    public const CORRELATION_ID_ATTRIBUTE = '_access_correlation_id';
    public const STARTED_AT_ATTRIBUTE = '_access_started_at';
    private const GENERATED_REQUEST_ID_BYTES = 12;
    private const MAX_REQUEST_ID_LENGTH = 64;
    private const MIN_REQUEST_ID_LENGTH = 8;
    private const REQUEST_ID_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/';
    private const REDACTED_SEGMENT = '[redacted]';

    public function __construct(private ?ContentRouteLocalization $routeLocalization = null)
    {
    }

    public function markStarted(Request $request): void
    {
        if (!$request->attributes->has(self::STARTED_AT_ATTRIBUTE)) {
            $request->attributes->set(self::STARTED_AT_ATTRIBUTE, microtime(true));
        }

        $this->requestId($request);
        $this->correlationId($request);
    }

    public function requestId(Request $request): string
    {
        $existing = $request->attributes->get(self::REQUEST_ID_ATTRIBUTE);

        if (is_string($existing) && '' !== $existing) {
            return $existing;
        }

        $requestId = $this->generateRequestId();
        $request->attributes->set(self::REQUEST_ID_ATTRIBUTE, $requestId);

        return $requestId;
    }

    public function correlationId(Request $request): string
    {
        $existing = $request->attributes->get(self::CORRELATION_ID_ATTRIBUTE);

        if (is_string($existing) && '' !== $existing) {
            return $existing;
        }

        $correlationId = $this->headerToken($request->headers->get('X-Correlation-ID'))
            ?? $this->headerToken($request->headers->get('X-Request-ID'))
            ?? 'n/a';
        $request->attributes->set(self::CORRELATION_ID_ATTRIBUTE, $correlationId);

        return $correlationId;
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
        $segments = $this->segments($request);

        return match (true) {
            $this->matchesSegments($segments, 'admin') => 'admin',
            $this->matchesSegments($segments, 'editor') => 'editor',
            $this->matchesSegments($segments, 'api') => 'api',
            $this->matchesSegments($segments, 'setup') => 'setup',
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
    private function segments(Request $request): array
    {
        $segments = array_values(array_filter(explode('/', trim($request->getPathInfo(), '/')), static fn (string $segment): bool => '' !== $segment));
        $locale = $this->localePrefix($request);

        if (is_string($locale) && '' !== $locale && ($segments[0] ?? null) === $locale) {
            array_shift($segments);
        }

        return $segments;
    }

    private function localePrefix(Request $request): ?string
    {
        $segments = explode('/', trim($request->getPathInfo(), '/'));
        $firstSegment = $segments[0] ?? '';

        if ('' === $firstSegment || !$this->hasLocalizedReservedPath($segments)) {
            return null;
        }

        $locale = $request->attributes->get('_locale');
        if (is_string($locale) && $firstSegment === $locale) {
            return $firstSegment;
        }

        if (null !== $this->routeLocalization && $this->routeLocalization->isEnabled() && in_array($firstSegment, $this->routeLocalization->availableLanguages(), true)) {
            return $firstSegment;
        }

        return null;
    }

    /**
     * @param list<string> $pathSegments
     */
    private function matchesSegments(array $pathSegments, string ...$segments): bool
    {
        foreach ($segments as $index => $segment) {
            if (($pathSegments[$index] ?? null) !== $segment) {
                return false;
            }
        }

        return [] !== $segments;
    }

    /**
     * @param list<string> $segments
     */
    private function hasLocalizedReservedPath(array $segments): bool
    {
        return in_array($segments[1] ?? '', ['admin', 'api', 'editor', 'setup'], true);
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

        if (strlen($value) < self::MIN_REQUEST_ID_LENGTH || strlen($value) > self::MAX_REQUEST_ID_LENGTH) {
            return null;
        }

        return 1 === preg_match(self::REQUEST_ID_PATTERN, $value) ? $value : null;
    }

    private function generateRequestId(): string
    {
        return bin2hex(random_bytes(self::GENERATED_REQUEST_ID_BYTES));
    }
}
