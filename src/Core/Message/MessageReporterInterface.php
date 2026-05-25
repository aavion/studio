<?php

declare(strict_types=1);

namespace App\Core\Message;

interface MessageReporterInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function report(Message $message, array $context = []): Message;

    /**
     * @param iterable<array{message: Message, context?: array<string, mixed>}> $records
     *
     * @return list<Message>
     */
    public function reportBatch(iterable $records): array;
}
