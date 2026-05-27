<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RecordingMessageBus implements MessageBusInterface
{
    /**
     * @var list<object>
     */
    private array $messages = [];

    /**
     * @param array<object> $stamps
     */
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message;

        return new Envelope($message, $stamps);
    }

    /**
     * @return list<object>
     */
    public function messages(): array
    {
        return $this->messages;
    }
}
