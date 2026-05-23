<?php

declare(strict_types=1);

namespace App\Core\Workflow;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;

final readonly class OperationIssue
{
    public function __construct(private Message $message)
    {
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $parameters
     */
    public static function create(
        string $code,
        string $translationKey,
        array $parameters = [],
        array $context = [],
        ?MessageLevel $level = null,
    ): self {
        return new self(Message::create($code, $translationKey, $parameters, $context, $level));
    }

    public static function fromMessage(Message $message): self
    {
        return new self($message);
    }

    public function message(): Message
    {
        return $this->message;
    }

    public function code(): string
    {
        return $this->message->code();
    }

    public function translationKey(): string
    {
        return $this->message->translationKey();
    }

    public function level(): MessageLevel
    {
        return $this->message->level();
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return $this->message->parameters();
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->message->context();
    }

    /**
     * @return array{level: string, code: string, translation_key: string, parameters: array<string, mixed>, context: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'level' => $this->level()->value,
            'code' => $this->code(),
            'translation_key' => $this->translationKey(),
            'parameters' => $this->parameters(),
            'context' => $this->context(),
        ];
    }
}
