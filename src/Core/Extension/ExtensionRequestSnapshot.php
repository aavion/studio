<?php

declare(strict_types=1);

namespace App\Core\Extension;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Throwable;

final readonly class ExtensionRequestSnapshot
{
    private const MAX_ITEMS = 200;
    private const MAX_STRING_LENGTH = 2048;
    private const MAX_DEPTH = 5;

    public function __construct(private RequestStack $requestStack)
    {
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function snapshot(string $extensionName, array $options = []): array
    {
        if (!ExtensionManifestSpec::isValidSlug($extensionName)) {
            return [];
        }

        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            return [];
        }

        try {
            $limits = $this->limits($options);

            return [
                'method' => $request->getMethod(),
                'path' => $request->getPathInfo(),
                'route' => $this->stringOrNull($request->attributes->get('_route')),
                'locale' => $request->getLocale(),
                'query' => $this->sanitizeArray($request->query->all(), $limits),
                'body' => $this->sanitizeArray($request->request->all(), $limits),
                'headers' => $this->headers($request, $limits),
                'cookies' => $this->cookies($request, $limits),
                'files' => $this->files($request->files->all(), $limits),
            ];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array{items: int, string_length: int, depth: int}
     */
    private function limits(array $options): array
    {
        return [
            'items' => $this->boundedInt($options['max_items'] ?? self::MAX_ITEMS, 1, self::MAX_ITEMS),
            'string_length' => $this->boundedInt($options['max_string_length'] ?? 512, 1, self::MAX_STRING_LENGTH),
            'depth' => $this->boundedInt($options['max_depth'] ?? self::MAX_DEPTH, 1, self::MAX_DEPTH),
        ];
    }

    private function boundedInt(mixed $value, int $min, int $max): int
    {
        $value = is_numeric($value) ? (int) $value : $max;

        return max($min, min($max, $value));
    }

    /**
     * @param array<string, mixed> $values
     * @param array{items: int, string_length: int, depth: int} $limits
     * @return array<string, mixed>
     */
    private function sanitizeArray(array $values, array $limits, int $depth = 0): array
    {
        if ($depth >= $limits['depth']) {
            return ['_truncated' => true];
        }

        $sanitized = [];
        $count = 0;

        foreach ($values as $key => $value) {
            if (++$count > $limits['items']) {
                $sanitized['_truncated'] = true;
                break;
            }

            $key = is_string($key) ? $this->cleanKey($key) : (string) $key;
            $sanitized[$key] = $this->sensitiveKey($key)
                ? '[redacted]'
                : $this->sanitizeValue($value, $limits, $depth + 1);
        }

        return $sanitized;
    }

    /**
     * @param array{items: int, string_length: int, depth: int} $limits
     */
    private function sanitizeValue(mixed $value, array $limits, int $depth): mixed
    {
        if (null === $value || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value) || $value instanceof \Stringable) {
            return $this->truncate((string) $value, $limits['string_length']);
        }

        if (is_array($value)) {
            return $this->sanitizeArray($value, $limits, $depth);
        }

        return '[unsupported]';
    }

    /**
     * @param array{items: int, string_length: int, depth: int} $limits
     * @return array<string, mixed>
     */
    private function headers(Request $request, array $limits): array
    {
        $headers = [];
        $count = 0;

        foreach ($request->headers->all() as $name => $values) {
            if (++$count > $limits['items']) {
                $headers['_truncated'] = true;
                break;
            }

            $name = strtolower($this->cleanKey($name));
            $headers[$name] = $this->sensitiveKey($name)
                ? '[redacted]'
                : array_map(fn (string $value): string => $this->truncate($value, $limits['string_length']), $values);
        }

        return $headers;
    }

    /**
     * @param array{items: int, string_length: int, depth: int} $limits
     * @return array<string, array{present: bool, size: int}>
     */
    private function cookies(Request $request, array $limits): array
    {
        $cookies = [];
        $count = 0;

        foreach ($request->cookies->all() as $name => $value) {
            if (++$count > $limits['items']) {
                break;
            }

            $cookies[$this->cleanKey((string) $name)] = [
                'present' => true,
                'size' => is_scalar($value) ? strlen((string) $value) : 0,
            ];
        }

        return $cookies;
    }

    /**
     * @param array<string, mixed> $files
     * @param array{items: int, string_length: int, depth: int} $limits
     * @return array<string, mixed>
     */
    private function files(array $files, array $limits, int $depth = 0): array
    {
        if ($depth >= $limits['depth']) {
            return ['_truncated' => true];
        }

        $snapshot = [];
        $count = 0;

        foreach ($files as $key => $file) {
            if (++$count > $limits['items']) {
                $snapshot['_truncated'] = true;
                break;
            }

            $key = is_string($key) ? $this->cleanKey($key) : (string) $key;
            if ($file instanceof UploadedFile) {
                $snapshot[$key] = [
                    'name' => $this->truncate(str_replace(["\0", '/', '\\'], '', $file->getClientOriginalName()), 180),
                    'size' => $file->getSize(),
                    'mime_type' => $file->getClientMimeType(),
                    'error' => $file->getError(),
                    'valid' => $file->isValid(),
                ];
                continue;
            }

            $snapshot[$key] = is_array($file) ? $this->files($file, $limits, $depth + 1) : '[unsupported]';
        }

        return $snapshot;
    }

    private function sensitiveKey(string $key): bool
    {
        return 1 === preg_match('/(auth|authorization|cookie|csrf|credential|key|password|secret|session|token)/i', $key);
    }

    private function cleanKey(string $key): string
    {
        return '' === trim($key) ? 'field' : $this->truncate(str_replace(["\0", "\r", "\n"], '', trim($key)), 120);
    }

    private function truncate(string $value, int $length): string
    {
        return strlen($value) > $length ? substr($value, 0, $length) : $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && '' !== trim($value) ? $value : null;
    }
}
