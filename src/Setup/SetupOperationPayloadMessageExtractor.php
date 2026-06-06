<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use JsonException;
use Throwable;

final class SetupOperationPayloadMessageExtractor
{
    /**
     * @return array<string, mixed>
     */
    public function contextFromOutput(string $output): array
    {
        $payload = $this->jsonPayload($output);

        if (null === $payload) {
            return [];
        }

        $messages = $this->importantMessagesFromOperationPayload($payload);

        if ([] === $messages) {
            return [];
        }

        return [
            '_messages' => $messages,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jsonPayload(string $output): ?array
    {
        $output = trim($output);

        if ('' === $output) {
            return null;
        }

        try {
            $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<Message>
     */
    private function importantMessagesFromOperationPayload(array $payload): array
    {
        $messages = [];

        foreach (($payload['action_log']['entries'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            foreach (($entry['messages'] ?? []) as $message) {
                $message = is_array($message) ? $this->messageFromPayload($message) : null;
                if (null !== $message && $this->shouldSurfaceNestedMessage($message)) {
                    $messages[] = $message;
                }
            }
        }

        foreach (($payload['result']['messages'] ?? []) as $message) {
            $message = is_array($message) ? $this->messageFromPayload($message) : null;
            if (null !== $message && $this->shouldSurfaceNestedMessage($message)) {
                $messages[] = $message;
            }
        }

        return $this->uniqueMessages($messages);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function messageFromPayload(array $payload): ?Message
    {
        $code = $payload['code'] ?? null;
        $translationKey = $payload['translation_key'] ?? null;

        if (!is_string($code) || !is_string($translationKey)) {
            return null;
        }

        $level = is_string($payload['level'] ?? null) ? MessageLevel::tryFrom($payload['level']) : null;

        try {
            return Message::create(
                $code,
                $translationKey,
                is_array($payload['parameters'] ?? null) ? $payload['parameters'] : [],
                is_array($payload['context'] ?? null) ? $payload['context'] : [],
                $level,
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function shouldSurfaceNestedMessage(Message $message): bool
    {
        return in_array($message->level(), [
            MessageLevel::Exception,
            MessageLevel::Error,
            MessageLevel::Warning,
        ], true);
    }

    /**
     * @param list<Message> $messages
     *
     * @return list<Message>
     */
    private function uniqueMessages(array $messages): array
    {
        $unique = [];
        $seen = [];

        foreach ($messages as $message) {
            $key = hash('sha256', serialize($message->toArray()));

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $message;
        }

        return $unique;
    }
}
