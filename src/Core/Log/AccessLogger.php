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
    ) {
    }

    public function log(Request $request, Response $response): void
    {
        $clientIp = $request->getClientIp() ?? self::GEO_PLACEHOLDER;

        $this->logger->info('access.request', [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'route' => $this->route($request),
            'query_string' => $request->getQueryString() ?? '',
            'http_status' => $response->getStatusCode(),
            'visitor_id' => $this->visitorIdGenerator->generate($request),
            'ip' => $clientIp,
            'client_ip' => $clientIp,
            'proxy_client_ip' => $this->visitorIdGenerator->proxyClientIp($request),
            'proxy_ip_chain' => $this->visitorIdGenerator->proxyIpChain($request),
            'user_agent' => $this->userAgent($request),
            'city' => self::GEO_PLACEHOLDER,
            'state' => self::GEO_PLACEHOLDER,
            'country' => self::GEO_PLACEHOLDER,
            'continent' => self::GEO_PLACEHOLDER,
        ]);
    }

    private function route(Request $request): string
    {
        $route = $request->attributes->get('_route');

        return is_string($route) && '' !== $route ? $route : self::GEO_PLACEHOLDER;
    }

    private function userAgent(Request $request): string
    {
        $userAgent = trim((string) $request->headers->get('User-Agent', self::GEO_PLACEHOLDER));

        return '' === $userAgent ? self::GEO_PLACEHOLDER : substr($userAgent, 0, 500);
    }
}
