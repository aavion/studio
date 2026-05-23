<?php

declare(strict_types=1);

namespace App\Core\Message;

use InvalidArgumentException;

final class MessageException extends InvalidArgumentException
{
    private function __construct(private readonly Message $messageObject)
    {
        parent::__construct($messageObject->translationKey());
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $context
     */
    public static function forMessage(
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
        return $this->messageObject;
    }

    public function code(): string
    {
        return $this->messageObject->code();
    }

    public function messageKey(): string
    {
        return $this->messageObject->translationKey();
    }

    public function level(): MessageLevel
    {
        return $this->messageObject->level();
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return $this->messageObject->parameters();
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->messageObject->context();
    }
}
