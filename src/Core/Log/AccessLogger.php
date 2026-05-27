<?php

declare(strict_types=1);

namespace App\Core\Log;

use App\Core\Geo\GeoIpResolverInterface;
use App\Core\Statistics\VisitorIdGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AccessLogger implements AccessLoggerInterface
{
    private const GEO_PLACEHOLDER = 'n/a';
    private const REDACTED = '[redacted]';

    public function __construct(
        private LoggerInterface $logger,
        private VisitorIdGenerator $visitorIdGenerator,
        private AccessRequestMetadata $accessRequestMetadata,
        private GeoIpResolverInterface $geoIpResolver,
    ) {
    }

    public function log(Request $request, Response $response): void
    {
        $clientIp = $request->getClientIp() ?? self::GEO_PLACEHOLDER;
        $geoIp = $this->geoIpResolver->resolve($this->visitorIdGenerator->sourceIp($request));

        $this->logger->info('access.request', [
            'request_id' => $this->accessRequestMetadata->requestId($request),
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'requested_path' => $request->getPathInfo(),
            'route' => $this->accessRequestMetadata->resolvedRoute($request),
            'resolved_route' => $this->accessRequestMetadata->resolvedRoute($request),
            'surface' => $this->accessRequestMetadata->surface($request),
            'query_string' => $this->redactedQueryString($request),
            'http_status' => $response->getStatusCode(),
            'duration_ms' => $this->accessRequestMetadata->durationMs($request),
            'visitor_id' => $this->visitorIdGenerator->generate($request),
            'scheme' => $request->getScheme(),
            'host' => $request->getHost(),
            'ip' => $clientIp,
            'client_ip' => $clientIp,
            'proxy_client_ip' => $this->visitorIdGenerator->proxyClientIp($request),
            'proxy_ip_chain' => $this->visitorIdGenerator->proxyIpChain($request),
            'user_agent' => $this->userAgent($request),
            'referrer' => $this->accessRequestMetadata->referrer($request),
            'referrer_host' => $this->accessRequestMetadata->referrerHost($request),
            'accept_language' => substr($request->headers->get('Accept-Language', self::GEO_PLACEHOLDER), 0, 255),
            'preferred_language' => $this->accessRequestMetadata->preferredLanguage($request),
            'request_content_type' => $this->accessRequestMetadata->contentType($request->headers->get('Content-Type')),
            'response_content_type' => $this->accessRequestMetadata->contentType($response->headers->get('Content-Type')),
            'response_size' => $this->accessRequestMetadata->responseSize($response),
            'city' => $geoIp->city,
            'state' => $geoIp->state,
            'country' => $geoIp->country,
            'continent' => $geoIp->continent,
        ]);
    }

    private function userAgent(Request $request): string
    {
        $userAgent = trim((string) $request->headers->get('User-Agent', self::GEO_PLACEHOLDER));

        return '' === $userAgent ? self::GEO_PLACEHOLDER : substr($userAgent, 0, 500);
    }

    private function redactedQueryString(Request $request): string
    {
        $queryString = $request->getQueryString();

        if (null === $queryString || '' === $queryString) {
            return '';
        }

        parse_str($queryString, $parameters);

        if ([] === $parameters) {
            return self::REDACTED;
        }

        return http_build_query($this->redactParameters($parameters), '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    private function redactParameters(array $parameters): array
    {
        $redacted = [];

        foreach ($parameters as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $redacted[$key] = self::REDACTED;
                continue;
            }

            $redacted[$key] = is_array($value) ? $this->redactParameters($value) : $value;
        }

        return $redacted;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $key));

        return 1 === preg_match('/(?:password|secret|token|credential|authorization|cookie|hmac|encrypted|api_key|private_key|code|signature|signed|session|csrf|nonce|reset|invite)/', $normalized);
    }
}
