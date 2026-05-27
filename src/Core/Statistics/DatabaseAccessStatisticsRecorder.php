<?php

declare(strict_types=1);

namespace App\Core\Statistics;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class DatabaseAccessStatisticsRecorder implements AccessStatisticsRecorderInterface
{
    private const PLACEHOLDER = 'n/a';

    public function __construct(
        private Connection $connection,
        private string $visitorSecret,
    ) {
    }

    public function record(Request $request, Response $response): void
    {
        try {
            $this->connection->insert('access_statistic_event', [
                'uid' => $this->uuid(),
                'occurred_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'visitor_id' => $this->visitorId($request),
                'method' => substr($request->getMethod(), 0, 16),
                'path' => substr($request->getPathInfo(), 0, 1024),
                'route' => substr($this->route($request), 0, 190),
                'http_status' => $response->getStatusCode(),
                'city' => self::PLACEHOLDER,
                'state' => self::PLACEHOLDER,
                'country' => self::PLACEHOLDER,
                'continent' => self::PLACEHOLDER,
                'metadata' => json_encode(['query_present' => null !== $request->getQueryString()], JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable) {
            return;
        }
    }

    private function visitorId(Request $request): string
    {
        return hash_hmac('sha256', $this->visitorSourceIp($request).'|'.$this->userAgent($request), $this->visitorSecret);
    }

    private function visitorSourceIp(Request $request): string
    {
        return $this->proxyIpChain($request)[0] ?? $request->getClientIp() ?? self::PLACEHOLDER;
    }

    private function userAgent(Request $request): string
    {
        $userAgent = trim((string) $request->headers->get('User-Agent', self::PLACEHOLDER));

        return '' === $userAgent ? self::PLACEHOLDER : substr(strtolower($userAgent), 0, 500);
    }

    private function route(Request $request): string
    {
        $route = $request->attributes->get('_route');

        return is_string($route) && '' !== $route ? $route : self::PLACEHOLDER;
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

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
