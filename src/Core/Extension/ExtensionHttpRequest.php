<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Config\Config;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final readonly class ExtensionHttpRequest
{
    public const ALLOW_PRIVATE_NETWORKS_KEY = 'extension.http.allow_private_networks';

    private const DEFAULT_TIMEOUT_SECONDS = 5.0;
    private const MAX_TIMEOUT_SECONDS = 15.0;
    private const DEFAULT_MAX_RESPONSE_BYTES = 1048576;
    private const MAX_PAYLOAD_BYTES = 1048576;
    private const ALLOWED_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    public function __construct(
        private HttpClientInterface $httpClient,
        private ?Config $config = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return array{ok: bool, status: int|null, headers: array<string, list<string>>, body: string, json: mixed, error: string|null}
     */
    public function request(string $extensionName, string $method, string $url, mixed $payload = null, array $options = []): array
    {
        if (!ExtensionManifestSpec::isValidSlug($extensionName)) {
            return $this->failure('invalid_extension');
        }

        $method = strtoupper(trim($method));
        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            return $this->failure('invalid_method');
        }

        $urlParts = parse_url($url);
        if (!is_array($urlParts) || !isset($urlParts['scheme'], $urlParts['host'])) {
            return $this->failure('invalid_url');
        }

        $scheme = strtolower((string) $urlParts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return $this->failure('invalid_scheme');
        }

        $host = $this->normalizeHost((string) $urlParts['host']);
        if ('' === $host) {
            return $this->failure('invalid_url');
        }

        $resolve = [];
        if (!$this->privateNetworksAllowed()) {
            $resolvedIp = $this->publicResolutionFor($host);
            if (null === $resolvedIp) {
                return $this->failure('private_network_blocked');
            }

            if (!filter_var($host, FILTER_VALIDATE_IP)) {
                $resolve[$host] = $resolvedIp;
            }
        }

        $clientOptions = [
            'headers' => $this->headers($options['headers'] ?? []),
            'timeout' => $this->timeout($options['timeout'] ?? null),
            'max_duration' => $this->timeout($options['timeout'] ?? null),
            'max_redirects' => 0,
        ];

        if ([] !== $resolve) {
            $clientOptions['resolve'] = $resolve;
        }

        $payloadOptions = $this->payloadOptions($payload);
        if (isset($payloadOptions['error'])) {
            return $this->failure($payloadOptions['error']);
        }

        $clientOptions = [...$clientOptions, ...$payloadOptions];
        $maxBytes = $this->maxResponseBytes($options['max_bytes'] ?? null);

        try {
            $response = $this->httpClient->request($method, $url, $clientOptions);
            $status = $response->getStatusCode();
            $headers = $this->normalizeHeaders($response->getHeaders(false));
            $body = '';

            foreach ($this->httpClient->stream($response) as $chunk) {
                $body .= $chunk->getContent();

                if (strlen($body) > $maxBytes) {
                    return [
                        'ok' => false,
                        'status' => $status,
                        'headers' => $headers,
                        'body' => substr($body, 0, $maxBytes),
                        'json' => null,
                        'error' => 'response_too_large',
                    ];
                }
            }

            return [
                'ok' => $status >= 200 && $status < 400,
                'status' => $status,
                'headers' => $headers,
                'body' => $body,
                'json' => $this->json($body),
                'error' => null,
            ];
        } catch (TransportExceptionInterface) {
            return $this->failure('transport_failed');
        } catch (Throwable) {
            return $this->failure('request_failed');
        }
    }

    /**
     * @return array{ok: false, status: null, headers: array<string, list<string>>, body: '', json: null, error: string}
     */
    private function failure(string $error): array
    {
        return [
            'ok' => false,
            'status' => null,
            'headers' => [],
            'body' => '',
            'json' => null,
            'error' => $error,
        ];
    }

    private function normalizeHost(string $host): string
    {
        return strtolower(trim($host, " \t\n\r\0\x0B[]"));
    }

    private function privateNetworksAllowed(): bool
    {
        return true === $this->config?->get(self::ALLOW_PRIVATE_NETWORKS_KEY, false);
    }

    private function publicResolutionFor(string $host): ?string
    {
        if ('localhost' === $host || str_ends_with($host, '.localhost')) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isPublicIp($host) ? $host : null;
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (false === $records || [] === $records) {
            return null;
        }

        $publicIp = null;
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (!is_string($ip) || !$this->isPublicIp($ip)) {
                return null;
            }

            $publicIp ??= $ip;
        }

        return $publicIp;
    }

    private function isPublicIp(string $ip): bool
    {
        return false !== filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );
    }

    /**
     * @param mixed $headers
     * @return array<string, string|list<string>>
     */
    private function headers(mixed $headers): array
    {
        if (!is_array($headers)) {
            return [];
        }

        $normalized = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || '' === trim($name)) {
                continue;
            }

            if (is_string($value)) {
                $normalized[$name] = $value;
            } elseif (is_array($value)) {
                $normalized[$name] = array_values(array_filter($value, is_string(...)));
            }
        }

        return $normalized;
    }

    private function timeout(mixed $timeout): float
    {
        if (!is_int($timeout) && !is_float($timeout) && !is_numeric($timeout)) {
            return self::DEFAULT_TIMEOUT_SECONDS;
        }

        return max(0.1, min(self::MAX_TIMEOUT_SECONDS, (float) $timeout));
    }

    private function maxResponseBytes(mixed $bytes): int
    {
        if (!is_int($bytes) && !is_numeric($bytes)) {
            return self::DEFAULT_MAX_RESPONSE_BYTES;
        }

        return max(1, min(self::DEFAULT_MAX_RESPONSE_BYTES, (int) $bytes));
    }

    /**
     * @return array<string, mixed>|array{error: string}
     */
    private function payloadOptions(mixed $payload): array
    {
        if (null === $payload) {
            return [];
        }

        if (is_string($payload)) {
            if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
                return ['error' => 'payload_too_large'];
            }

            return ['body' => $payload];
        }

        if (is_scalar($payload) || is_array($payload)) {
            try {
                $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return ['error' => 'invalid_payload'];
            }

            if (strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
                return ['error' => 'payload_too_large'];
            }

            return ['json' => $payload];
        }

        return ['error' => 'invalid_payload'];
    }

    /**
     * @param array<string, list<string>> $headers
     * @return array<string, list<string>>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $values) {
            if (!is_string($name) || !is_array($values)) {
                continue;
            }

            $normalized[strtolower($name)] = array_values(array_filter($values, is_string(...)));
        }

        return $normalized;
    }

    private function json(string $body): mixed
    {
        if ('' === trim($body)) {
            return null;
        }

        try {
            return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }
}
