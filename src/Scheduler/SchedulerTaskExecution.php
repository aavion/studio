<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Core\Message\Message;

final readonly class SchedulerTaskExecution
{
    /**
     * @param array<string, mixed> $context
     * @param list<Message> $messages
     */
    public function __construct(
        private bool $success,
        private array $context = [],
        private array $messages = [],
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @param list<Message> $messages
     */
    public static function success(array $context = [], array $messages = []): self
    {
        return new self(true, $context, $messages);
    }

    /**
     * @param array<string, mixed> $context
     * @param list<Message> $messages
     */
    public static function failed(array $context = [], array $messages = []): self
    {
        return new self(false, $context, $messages);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * @return list<Message>
     */
    public function messages(): array
    {
        return $this->messages;
    }
}
