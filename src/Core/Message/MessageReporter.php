<?php

declare(strict_types=1);

namespace App\Core\Message;

use App\Core\Log\MessageLoggerInterface;

final readonly class MessageReporter implements MessageReporterInterface
{
    public function __construct(private MessageLoggerInterface $logger)
    {
    }

    public function report(Message $message, array $context = []): Message
    {
        $this->logger->log($message, $context);

        return $message;
    }

    public function reportBatch(iterable $records): array
    {
        $messages = [];
        $logRecords = [];

        foreach ($records as $record) {
            $message = $record['message'];

            if (!$message instanceof Message) {
                continue;
            }

            $messages[] = $message;
            $logRecords[] = [
                'message' => $message,
                'context' => $record['context'] ?? [],
            ];
        }

        $this->logger->logBatch($logRecords);

        return $messages;
    }
}
