<?php

declare(strict_types=1);

namespace App\Security\Abuse;

use Symfony\Component\HttpFoundation\Request;

final readonly class SuspiciousRequestPayloadMatcher
{
    private const MAX_PARAMETERS = 80;
    private const MAX_STRING_LENGTH = 2048;
    private const MAX_BODY_LENGTH = 8192;

    /**
     * @var list<string>
     */
    private const SCALAR_ONLY_PARAMETER_NAMES = [
        '_auto_ban_recovery_token',
        '_csrf_token',
        '_form_id',
        '_method',
        '_setup_action',
        'auth',
        'bypass',
        'email',
        'password',
        'return_to',
        'token',
        'username',
    ];

    /**
     * @var array<string, string>
     */
    private const ATTACK_PATTERNS = [
        'sql_union_select' => '~\bunion\s+(?:all\s+)?select\b~i',
        'sql_boolean_tautology' => '~(?:\bor\b|\band\b)\s+[\'"]?\d+[\'"]?\s*=\s*[\'"]?\d+[\'"]?(?:\s|$|--|#|/\*)~i',
        'sql_timing_probe' => '~\b(?:benchmark|pg_sleep|sleep)\s*\(~i',
        'sql_schema_probe' => '~\b(?:information_schema|sqlite_master|sysobjects)\b~i',
        'path_traversal' => '~(?:^|[\\\\/])\.\.(?:[\\\\/]|$)~',
        'sensitive_file_probe' => '~(?:/etc/passwd|/proc/self/environ|boot\.ini|wp-config\.php)~i',
        'php_stream_wrapper' => '~\b(?:data|expect|file|php)://~i',
        'jndi_lookup' => '~\$\{\s*jndi\s*:~i',
        'script_tag' => '~<\s*script\b~i',
    ];

    /**
     * @return array{signatures: list<string>, parameters: list<array{source: string, name: string, kind: string}>}|null
     */
    public function match(Request $request): ?array
    {
        $signatures = [];
        $parameters = [];

        foreach (['query' => $request->query->all(), 'request' => $request->request->all(), ...$this->bodyPayload($request)] as $source => $payload) {
            $this->scanPayload($source, $payload, $signatures, $parameters);
        }

        if ([] === $signatures) {
            return null;
        }

        return [
            'signatures' => array_values(array_unique($signatures)),
            'parameters' => array_slice($parameters, 0, self::MAX_PARAMETERS),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function bodyPayload(Request $request): array
    {
        if (!$this->jsonLikeRequest($request)) {
            return [];
        }

        $content = $this->boundedBody($request, self::MAX_BODY_LENGTH);
        if (null === $content) {
            return [];
        }

        if ('' === trim($content)) {
            return [];
        }

        try {
            $decoded = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['raw_body' => ['body' => $content]];
        }

        if (is_array($decoded)) {
            return ['json' => $decoded];
        }

        return ['json' => ['body' => $decoded]];
    }

    private function jsonLikeRequest(Request $request): bool
    {
        $contentType = strtolower((string) $request->headers->get('Content-Type'));
        if (str_contains($contentType, '/json') || str_contains($contentType, '+json')) {
            return true;
        }

        $content = $this->boundedBody($request, 32);
        if (null === $content) {
            return false;
        }

        $content = ltrim($content);

        return str_starts_with($content, '{') || str_starts_with($content, '[');
    }

    private function boundedBody(Request $request, int $limit): ?string
    {
        $length = $this->declaredContentLength($request);
        if (null === $length || $length > self::MAX_BODY_LENGTH) {
            return null;
        }

        $content = $request->getContent();
        if (strlen($content) > self::MAX_BODY_LENGTH) {
            return null;
        }

        return substr($content, 0, max(0, $limit));
    }

    private function declaredContentLength(Request $request): ?int
    {
        $length = $request->headers->get('Content-Length') ?? $request->server->get('CONTENT_LENGTH');
        if (!is_string($length) && !is_int($length)) {
            return null;
        }

        $length = trim((string) $length);
        if ('' === $length || 1 !== preg_match('/^\d+$/', $length)) {
            return null;
        }

        return (int) $length;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $signatures
     * @param list<array{source: string, name: string, kind: string}> $parameters
     */
    private function scanPayload(string $source, array $payload, array &$signatures, array &$parameters, string $prefix = ''): void
    {
        foreach ($payload as $name => $value) {
            if (self::MAX_PARAMETERS <= count($parameters)) {
                return;
            }

            $path = $this->parameterPath($prefix, is_string($name) ? $name : (string) $name);
            $baseName = $this->baseParameterName($path);

            if (is_array($value)) {
                if (in_array($baseName, self::SCALAR_ONLY_PARAMETER_NAMES, true)) {
                    $signatures[] = 'malformed_parameter';
                    $parameters[] = ['source' => $source, 'name' => $this->short($path), 'kind' => 'malformed_parameter'];
                }

                $this->scanPayload($source, $value, $signatures, $parameters, $path);

                continue;
            }

            if (!is_scalar($value)) {
                continue;
            }

            foreach ($this->attackSignatures((string) $value) as $signature) {
                $signatures[] = $signature;
                $parameters[] = ['source' => $source, 'name' => $this->short($path), 'kind' => 'attack_pattern'];
            }
        }
    }

    /**
     * @return list<string>
     */
    private function attackSignatures(string $value): array
    {
        $value = $this->normalizedValue($value);
        if ('' === $value) {
            return [];
        }

        $matches = [];
        foreach (self::ATTACK_PATTERNS as $signature => $pattern) {
            if (1 === preg_match($pattern, $value)) {
                $matches[] = $signature;
            }
        }

        return $matches;
    }

    private function normalizedValue(string $value): string
    {
        $value = mb_substr($value, 0, self::MAX_STRING_LENGTH);
        for ($i = 0; $i < 2; ++$i) {
            $decoded = rawurldecode($value);
            if ($decoded === $value) {
                break;
            }

            $value = $decoded;
        }

        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function parameterPath(string $prefix, string $name): string
    {
        $name = trim($name);
        if ('' === $name) {
            $name = 'n/a';
        }

        return '' === $prefix ? $name : $prefix.'.'.$name;
    }

    private function baseParameterName(string $path): string
    {
        $segments = explode('.', strtolower($path));

        return (string) end($segments);
    }

    private function short(string $value): string
    {
        return mb_substr($value, 0, 120);
    }
}
