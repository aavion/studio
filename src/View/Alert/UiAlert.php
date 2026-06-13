<?php

declare(strict_types=1);

namespace App\View\Alert;

use App\Core\Message\MessageLevel;
use InvalidArgumentException;

final readonly class UiAlert
{
    /**
     * @param array<string, mixed> $context
     * @param list<array<string, mixed>> $actions
     */
    public function __construct(
        private string $message,
        private string $level = 'info',
        private bool $persistent = false,
        private ?string $code = null,
        private ?string $translationKey = null,
        private array $context = [],
        private string $mode = 'auto',
        private ?string $id = null,
        private array $actions = [],
        private bool $loading = false,
    ) {
        if ('' === trim($this->message)) {
            throw new InvalidArgumentException('UI alert message must not be empty.');
        }
    }

    /**
     * @param list<array<string, mixed>> $actions
     */
    public static function fromLevel(
        string $level,
        string $message,
        bool $persistent = false,
        string $mode = 'auto',
        ?string $id = null,
        array $actions = [],
        bool $loading = false,
    ): self
    {
        return new self($message, $level, $persistent, mode: $mode, id: $id, actions: $actions, loading: $loading);
    }

    /**
     * @param array<string, mixed> $context
     * @param list<array<string, mixed>> $actions
     */
    public static function translated(
        string $message,
        MessageLevel|string $level,
        string $code,
        string $translationKey,
        array $context = [],
        bool $persistent = false,
        string $mode = 'auto',
        ?string $id = null,
        array $actions = [],
        bool $loading = false,
    ): self {
        return new self(
            $message,
            $level instanceof MessageLevel ? $level->value : $level,
            $persistent,
            $code,
            $translationKey,
            $context,
            $mode,
            $id,
            $actions,
            $loading,
        );
    }

    /**
     * @return array{message: string, level: string, persistent: bool, mode: string, loading: bool, id?: string, actions?: list<array<string, mixed>>, code?: string, translation_key?: string, context?: array<string, mixed>}
     */
    public function toArray(): array
    {
        $payload = [
            'message' => $this->message,
            'level' => $this->normalizedLevel(),
            'persistent' => $this->persistent,
            'mode' => $this->normalizedMode(),
            'loading' => $this->loading,
        ];

        if (null !== $this->id && '' !== trim($this->id)) {
            $payload['id'] = $this->id;
        }

        if ([] !== $this->actions) {
            $payload['actions'] = $this->actions;
        }

        if (null !== $this->code) {
            $payload['code'] = $this->code;
        }

        if (null !== $this->translationKey) {
            $payload['translation_key'] = $this->translationKey;
        }

        if ([] !== $this->context) {
            $payload['context'] = $this->context;
        }

        return $payload;
    }

    private function normalizedLevel(): string
    {
        return match (strtolower($this->level)) {
            'success' => 'success',
            'warn', 'warning' => 'warning',
            'error', 'danger' => 'error',
            'exception' => 'exception',
            'debug' => 'debug',
            default => 'info',
        };
    }

    private function normalizedMode(): string
    {
        return match (strtolower($this->mode)) {
            'hidden' => 'hidden',
            'persistent' => 'persistent',
            default => 'auto',
        };
    }
}
