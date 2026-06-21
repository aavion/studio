<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

final readonly class LiveOperationPresentationRedactor
{
    private const REDACTED = '[redacted]';

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function redact(array $context, int $depth = 0): array
    {
        if ($depth >= 5) {
            return ['_truncated' => true];
        }

        $redacted = [];
        $count = 0;

        foreach ($context as $key => $value) {
            if (++$count > 100) {
                $redacted['_truncated'] = true;
                break;
            }

            if (!is_string($key) && !is_int($key)) {
                continue;
            }

            $redacted[$key] = is_string($key) && $this->isSensitiveKey($key)
                ? self::REDACTED
                : $this->redactValue($value, $depth + 1);
        }

        return $redacted;
    }

    private function redactValue(mixed $value, int $depth): mixed
    {
        if (null === $value || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value) || $value instanceof \Stringable) {
            $value = (string) $value;

            return strlen($value) > 500 ? substr($value, 0, 500) : $value;
        }

        if (is_array($value)) {
            return $this->redact($value, $depth);
        }

        return '[unsupported]';
    }

    private function isSensitiveKey(string $key): bool
    {
        return 1 === preg_match('/(?:api[_-]?key|auth|credential|database[_-]?password|password|secret|token)/i', $key);
    }
}
