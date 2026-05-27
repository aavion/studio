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
        $clientIp = $request->getClientIp() ?? self::GEO_PLACEHOLDER;

        $this->logger->info('access.request', [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'route' => $this->route($request),
            'query_string' => $request->getQueryString() ?? '',
            'http_status' => $response->getStatusCode(),
            'ip' => $clientIp,
            'client_ip' => $clientIp,
            'proxy_client_ip' => $this->proxyClientIp($request),
            'proxy_ip_chain' => $this->proxyIpChain($request),
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

    private function proxyClientIp(Request $request): string
    {
        return $this->proxyIpChain($request)[0] ?? self::GEO_PLACEHOLDER;
    }

    /**
     * @return list<string>
     */
    private function proxyIpChain(Request $request): array
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
}
