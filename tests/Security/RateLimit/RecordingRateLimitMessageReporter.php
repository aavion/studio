<?php

declare(strict_types=1);

namespace App\Tests\Security\RateLimit;

use App\Core\Message\Message;
use App\Core\Message\MessageReporterInterface;

final class RecordingRateLimitMessageReporter implements MessageReporterInterface
{
    /**
     * @var list<array{message: Message, context: array<string, mixed>}>
     */
    public array $records = [];

    public function report(Message $message, array $context = []): Message
    {
        $this->records[] = [
            'message' => $message,
            'context' => $context,
        ];

        return $message;
    }

    public function reportBatch(iterable $records): array
    {
        $messages = [];

        foreach ($records as $record) {
            $messages[] = $this->report($record['message'], $record['context'] ?? []);
        }

        return $messages;
    }
}
