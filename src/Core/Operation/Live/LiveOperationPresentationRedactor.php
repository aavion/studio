<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use App\Core\Workflow\WorkflowResult;

final readonly class LiveOperationPresentationRedactor
{
    private const REDACTED = '[redacted]';
    private const DIAGNOSTIC_MESSAGE_LEVELS = [
        'ERROR' => true,
        'EXCEPTION' => true,
        'WARN' => true,
        'WARNING' => true,
    ];

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function redact(array $context, int $depth = 0): array
    {
        if ($depth >= 5) {
            return ['_truncated' => true];
        }

        $redacted = [];
        $count = 0;

        foreach ($context as $key => $value) {
            if (++$count > 100) {
                $redacted['_truncated'] = true;
                break;
            }

            if (!is_string($key) && !is_int($key)) {
                continue;
            }

            $redacted[$key] = is_string($key) && $this->isSensitiveKey($key)
                ? self::REDACTED
                : $this->redactValue($value, $depth + 1);
        }

        return $redacted;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function messageList(mixed $messages): array
    {
        if (!is_array($messages)) {
            return [];
        }

        $list = [];

        foreach ($messages as $message) {
            if (is_array($message)) {
                $list[] = $this->message($message);
            }
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    public function message(array $message): array
    {
        $presented = [
            'level' => is_string($message['level'] ?? null) ? $message['level'] : null,
            'code' => is_string($message['code'] ?? null) ? $message['code'] : null,
            'translation_key' => is_string($message['translation_key'] ?? null) ? $message['translation_key'] : null,
            'parameters' => is_array($message['parameters'] ?? null) ? $this->redact($message['parameters']) : [],
        ];

        $level = is_string($message['level'] ?? null) ? strtoupper($message['level']) : '';
        $context = is_array($message['context'] ?? null) && isset(self::DIAGNOSTIC_MESSAGE_LEVELS[$level])
            ? $this->redact($message['context'])
            : [];

        if ([] !== $context) {
            $presented['context'] = $context;
        }

        return $presented;
    }

    /**
     * @param WorkflowResult<mixed> $result
     *
     * @return array<string, mixed>
     */
    public function workflowResult(WorkflowResult $result): array
    {
        $payload = $result->toArray();

        return [
            'status' => $payload['status'],
            'success' => $payload['success'],
            'recoverable' => $payload['recoverable'],
            'value' => $this->workflowValue($payload['value']),
            'issues' => $this->messageList($payload['issues']),
            'messages' => $this->messageList($payload['messages']),
            'context' => is_array($payload['context'] ?? null) ? $this->redact($payload['context']) : [],
        ];
    }

    private function redactValue(mixed $value, int $depth): mixed
    {
        if (null === $value || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value) || $value instanceof \Stringable) {
            $value = (string) $value;

            return strlen($value) > 500 ? substr($value, 0, 500) : $value;
        }

        if (is_array($value)) {
            return $this->redact($value, $depth);
        }

        return '[unsupported]';
    }

    private function isSensitiveKey(string $key): bool
    {
        return 1 === preg_match('/(?:api[_-]?key|auth|credential|database[_-]?password|message|password|reason|secret|token)/i', $key);
    }

    private function workflowValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $this->redactValue($value, 0);
        }

        if (isset($value['operation_id'], $value['token'], $value['operation'], $value['label'], $value['status'])) {
            return [
                'operation_id' => is_string($value['operation_id']) ? $value['operation_id'] : '',
                'token' => is_string($value['token']) ? $value['token'] : '',
                'operation' => is_string($value['operation']) ? $value['operation'] : '',
                'label' => is_string($value['label']) ? $value['label'] : '',
                'status' => is_string($value['status']) ? $value['status'] : '',
            ];
        }

        return $this->redact($value);
    }
}
