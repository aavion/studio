<?php

declare(strict_types=1);

namespace App\Core\Log;

use App\Core\Message\Message;
use DateTimeImmutable;
use DateTimeInterface;
use Throwable;
use UnitEnum;

final class FileMessageLogger implements MessageLoggerInterface
{
    private const REDACTED = '[redacted]';

    /**
     * @var array<string, true>
     */
    private array $seenSignatures = [];

    public function __construct(
        private readonly string $projectDir,
        private readonly string $environment,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(Message $message, array $context = []): void
    {
        $this->logBatch([
            [
                'message' => $message,
                'context' => $context,
            ],
        ]);
    }

    public function logBatch(iterable $records): void
    {
        $lines = [];
        $timestamp = (new DateTimeImmutable())->format(DATE_ATOM);

        foreach ($records as $record) {
            $message = $record['message'];
            $context = $record['context'] ?? [];

            if (!$message instanceof Message) {
                continue;
            }

            $entry = $this->formatEntry($timestamp, $message, $context);

            if (null !== $entry) {
                $lines[] = $entry;
            }
        }

        if ([] === $lines) {
            return;
        }

        $this->append(implode('', $lines));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function formatEntry(string $timestamp, Message $message, array $context): ?string
    {
        $context = $this->normalize([
            'code' => $message->code(),
            'parameters' => $message->parameters(),
            'message_context' => $message->context(),
            ...$context,
        ]);
        $signature = $this->signature($message, $context);

        if (isset($this->seenSignatures[$signature])) {
            return null;
        }

        $this->seenSignatures[$signature] = true;

        return sprintf(
            '[%s] [%s] %s%s%s%s',
            $timestamp,
            $message->level()->value,
            $message->translationKey(),
            PHP_EOL,
            $this->encodeContext($context),
            PHP_EOL,
        );
    }

    private function append(string $contents): void
    {
        $path = $this->logPath();
        $directory = dirname($path);

        try {
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                return;
            }

            file_put_contents($path, $contents, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            return;
        }
    }

    private function logPath(): string
    {
        return rtrim($this->projectDir, DIRECTORY_SEPARATOR.'/\\')
            .DIRECTORY_SEPARATOR.'var'
            .DIRECTORY_SEPARATOR.'log'
            .DIRECTORY_SEPARATOR.$this->environment
            .DIRECTORY_SEPARATOR.'operations.log';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function encodeContext(array $context): string
    {
        $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return false === $encoded ? '{}' : $encoded;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function signature(Message $message, array $context): string
    {
        return hash('sha256', implode('|', [
            $message->level()->value,
            $message->code(),
            $message->translationKey(),
            $this->encodeContext($context),
        ]));
    }

    private function normalize(mixed $value, string $key = ''): mixed
    {
        if ('' !== $key && $this->isSensitiveKey($key)) {
            return self::REDACTED;
        }

        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $itemKey => $itemValue) {
                $normalized[$itemKey] = $this->normalize($itemValue, is_string($itemKey) ? $itemKey : '');
            }

            return $normalized;
        }

        if (null === $value || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if ($value instanceof UnitEnum) {
            return $value instanceof \BackedEnum ? $value->value : $value->name;
        }

        if ($value instanceof Throwable) {
            return [
                'exception' => $value::class,
                'message' => $value->getMessage(),
            ];
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return get_debug_type($value);
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $key));

        return 1 === preg_match('/(?:password|secret|token|credential|authorization|cookie|hmac|encrypted|api_key|private_key)/', $normalized);
    }
}
