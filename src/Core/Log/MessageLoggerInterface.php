<?php

declare(strict_types=1);

namespace App\Core\Log;

use App\Core\Message\Message;

interface MessageLoggerInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function log(Message $message, array $context = []): void;

    /**
     * @param iterable<array{message: Message, context?: array<string, mixed>}> $records
     */
    public function logBatch(iterable $records): void;
}
