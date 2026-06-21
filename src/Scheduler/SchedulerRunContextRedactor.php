<?php

declare(strict_types=1);

namespace App\Scheduler;

final readonly class SchedulerRunContextRedactor
{
    private const MAX_STRING_LENGTH = 500;
    private const MAX_ITEMS = 100;
    private const MAX_DEPTH = 6;

    private const REDACTED_KEYS = [
        'command' => true,
        'command_line' => true,
        'cwd' => true,
        'error_excerpt' => true,
        'output_excerpt' => true,
        'stderr' => true,
        'stdout' => true,
    ];

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function redact(array $context): array
    {
        return $this->redactArray($context, 0);
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<string|int, mixed>
     */
    private function redactArray(array $values, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['_truncated' => true];
        }

        $redacted = [];
        $count = 0;

        foreach ($values as $key => $value) {
            if (++$count > self::MAX_ITEMS) {
                $redacted['_truncated'] = true;
                break;
            }

            if (!is_string($key) && !is_int($key)) {
                continue;
            }

            $redacted[$key] = is_string($key) && $this->redactedKey($key)
                ? '[redacted]'
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

            return strlen($value) > self::MAX_STRING_LENGTH ? substr($value, 0, self::MAX_STRING_LENGTH) : $value;
        }

        if (is_array($value)) {
            return $this->redactArray($value, $depth);
        }

        return '[unsupported]';
    }

    private function redactedKey(string $key): bool
    {
        $normalized = strtolower(trim($key));

        return isset(self::REDACTED_KEYS[$normalized])
            || 1 === preg_match('/(?:auth|authorization|credential|password|secret|token)/', $normalized);
    }
}
