<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Core\Message\Message;
use App\Core\Message\MessageReporterInterface;

final class NullMessageReporter implements MessageReporterInterface
{
    public function report(Message $message, array $context = []): Message
    {
        return $message;
    }

    public function reportBatch(iterable $records): array
    {
        $messages = [];

        foreach ($records as $record) {
            $messages[] = $record['message'];
        }

        return $messages;
    }
}
