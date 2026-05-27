<?php

declare(strict_types=1);

namespace App\Core\Log;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;
use UnitEnum;

final class MonologMessageLogger implements MessageLoggerInterface
{
    private const REDACTED = '[redacted]';

    /**
     * @var array<string, true>
     */
    private array $seenSignatures = [];

    public function __construct(private readonly LoggerInterface $logger)
    {
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
        foreach ($records as $record) {
            $message = $record['message'];
            $context = $record['context'] ?? [];

            if (!$message instanceof Message) {
                continue;
            }

            $context = $this->context($message, is_array($context) ? $context : []);
            $signature = $this->signature($message, $context);

            if (isset($this->seenSignatures[$signature])) {
                continue;
            }

            try {
                $this->logger->log($this->psrLevel($message->level()), $message->translationKey(), $context);
                $this->seenSignatures[$signature] = true;
            } catch (Throwable) {
                continue;
            }
        }
    }

    private function psrLevel(MessageLevel $level): string
    {
        return match ($level) {
            MessageLevel::Success => LogLevel::NOTICE,
            MessageLevel::Exception => LogLevel::CRITICAL,
            MessageLevel::Error => LogLevel::ERROR,
            MessageLevel::Warning => LogLevel::WARNING,
            MessageLevel::Info => LogLevel::INFO,
            MessageLevel::Debug => LogLevel::DEBUG,
        };
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function context(Message $message, array $context): array
    {
        return $this->normalize([
            'code' => $message->code(),
            'parameters' => $message->parameters(),
            'message_context' => $message->context(),
            ...$context,
        ]);
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

    private function encodeContext(array $context): string
    {
        $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return false === $encoded ? '{}' : $encoded;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $key));

        return 1 === preg_match('/(?:password|secret|token|credential|authorization|cookie|hmac|encrypted|api_key|private_key)/', $normalized);
    }
}
