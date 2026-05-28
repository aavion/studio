<?php

declare(strict_types=1);

namespace App\Core\Statistics;

use Symfony\Component\HttpFoundation\Request;

final readonly class VisitorIdGenerator
{
    private const PLACEHOLDER = 'n/a';

    public function __construct(private string $secret)
    {
    }

    public function generate(Request $request): string
    {
        return hash_hmac('sha256', $this->sourceIp($request).'|'.$this->normalizedUserAgent($request), $this->secret);
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
}
