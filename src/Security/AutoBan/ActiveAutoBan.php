<?php

declare(strict_types=1);

namespace App\Security\AutoBan;

use DateTimeImmutable;

final readonly class ActiveAutoBan
{
    private const TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        private string $key,
        private string $subjectType,
        private string $subjectIdentifier,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $expiresAt,
        private int $ttlSeconds,
        private array $context = [],
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function subjectType(): string
    {
        return $this->subjectType;
    }

    public function subjectIdentifier(): string
    {
        return $this->subjectIdentifier;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
    }

    public function retryAfterSeconds(DateTimeImmutable $now): int
    {
        return max(1, $this->expiresAt->getTimestamp() - $now->getTimestamp());
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'subject_type' => $this->subjectType,
            'subject_identifier' => $this->subjectIdentifier,
            'subject_label' => $this->subjectLabel(),
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'expires_at' => $this->expiresAt->format('Y-m-d H:i:s'),
            'ttl_seconds' => $this->ttlSeconds,
            'context' => $this->context,
        ];
    }

    public function subjectLabel(): string
    {
        return $this->subjectType.':'.substr($this->subjectIdentifier, 0, 12);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): ?self
    {
        foreach (['key', 'subject_type', 'subject_identifier', 'created_at', 'expires_at', 'ttl_seconds'] as $field) {
            if (!isset($payload[$field])) {
                return null;
            }
        }

        $createdAt = self::timestamp($payload['created_at']);
        $expiresAt = self::timestamp($payload['expires_at']);
        if (null === $createdAt || null === $expiresAt) {
            return null;
        }

        $key = self::text($payload['key']);
        $subjectType = self::text($payload['subject_type']);
        $subjectIdentifier = self::text($payload['subject_identifier']);
        $ttlSeconds = self::positiveInteger($payload['ttl_seconds']);
        if (null === $key || null === $subjectType || null === $subjectIdentifier || null === $ttlSeconds) {
            return null;
        }

        return new self(
            $key,
            $subjectType,
            $subjectIdentifier,
            $createdAt,
            $expiresAt,
            $ttlSeconds,
            is_array($payload['context'] ?? null) ? $payload['context'] : [],
        );
    }

    private static function timestamp(mixed $value): ?DateTimeImmutable
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ('' === $value || str_contains($value, "\0")) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!'.self::TIMESTAMP_FORMAT, $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $parsed instanceof DateTimeImmutable
            && (false === $errors || (0 === $errors['warning_count'] && 0 === $errors['error_count']))
                ? $parsed
                : null;
    }

    private static function text(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return '' === $value || str_contains($value, "\0") ? null : $value;
    }

    private static function positiveInteger(mixed $value): ?int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return null;
        }

        return max(1, (int) $value);
    }
}
