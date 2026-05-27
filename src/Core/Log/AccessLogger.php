<?php

declare(strict_types=1);

namespace App\Core\Log;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AccessLogger implements AccessLoggerInterface
{
    private const GEO_PLACEHOLDER = 'n/a';

    public function __construct(private LoggerInterface $logger)
    {
    }

    public function log(Request $request, Response $response): void
    {
        $this->logger->info('access.request', [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'route' => $this->route($request),
            'query_string' => $request->getQueryString() ?? '',
            'http_status' => $response->getStatusCode(),
            'ip' => $request->getClientIp() ?? self::GEO_PLACEHOLDER,
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
}
