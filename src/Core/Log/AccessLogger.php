<?php

declare(strict_types=1);

namespace App\Core\Log;

use App\Core\Statistics\VisitorIdGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AccessLogger implements AccessLoggerInterface
{
    private const GEO_PLACEHOLDER = 'n/a';

    public function __construct(
        private LoggerInterface $logger,
        private VisitorIdGenerator $visitorIdGenerator,
        private AccessRequestMetadata $accessRequestMetadata,
    ) {
    }

    public function log(Request $request, Response $response): void
    {
        $clientIp = $request->getClientIp() ?? self::GEO_PLACEHOLDER;

        $this->logger->info('access.request', [
            'request_id' => $this->accessRequestMetadata->requestId($request),
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'requested_path' => $request->getPathInfo(),
            'route' => $this->accessRequestMetadata->resolvedRoute($request),
            'resolved_route' => $this->accessRequestMetadata->resolvedRoute($request),
            'surface' => $this->accessRequestMetadata->surface($request),
            'query_string' => $request->getQueryString() ?? '',
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
            'city' => self::GEO_PLACEHOLDER,
            'state' => self::GEO_PLACEHOLDER,
            'country' => self::GEO_PLACEHOLDER,
            'continent' => self::GEO_PLACEHOLDER,
        ]);
    }

    private function userAgent(Request $request): string
    {
        $userAgent = trim((string) $request->headers->get('User-Agent', self::GEO_PLACEHOLDER));

        return '' === $userAgent ? self::GEO_PLACEHOLDER : substr($userAgent, 0, 500);
    }
}
