<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

final class LiveOperationRunPresenter
{
    private const STATUS_QUEUED = 'queued';
    private const STATUS_SUCCESS = 'success';
    private const STATUS_REQUIRES_REVIEW = 'requires_review';
    private const STATUS_FAILED = 'failed';
    private const TERMINAL_STATUSES = [self::STATUS_SUCCESS, self::STATUS_REQUIRES_REVIEW, self::STATUS_FAILED];
    private const DIAGNOSTIC_ENTRY_STATUSES = [
        'failed' => true,
        'warning' => true,
    ];

    public function __construct(private readonly LiveOperationPresentationRedactor $redactor = new LiveOperationPresentationRedactor())
    {
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    public function summary(array $state): array
    {
        $result = is_array($state['result'] ?? null) ? $state['result'] : null;
        $issues = is_array($result['issues'] ?? null) ? $result['issues'] : [];
        $firstIssue = $issues[0] ?? null;

        return [
            'operation_id' => (string) ($state['operation_id'] ?? ''),
            'operation' => (string) ($state['operation'] ?? ''),
            'label' => (string) ($state['label'] ?? ''),
            'status' => (string) ($state['status'] ?? self::STATUS_QUEUED),
            'created_at' => $state['created_at'] ?? null,
            'updated_at' => $state['updated_at'] ?? null,
            'started_at' => $state['started_at'] ?? null,
            'finished_at' => $state['finished_at'] ?? null,
            'cursor' => (int) ($state['cursor'] ?? 0),
            'progress' => is_array($state['progress'] ?? null) ? $state['progress'] : ['index' => 0, 'total' => 0],
            'result_status' => is_array($result) ? ($result['status'] ?? null) : null,
            'issue' => is_array($firstIssue) ? $this->redactor->message($firstIssue) : null,
        ];
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    public function report(array $state): array
    {
        $entries = [];

        foreach (is_array($state['entries'] ?? null) ? $state['entries'] : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $entries[] = $this->entry($entry);
        }

        $result = is_array($state['result'] ?? null) ? $state['result'] : null;

        return [
            ...$this->summary($state),
            'entries' => $entries,
            'result' => null === $result ? null : [
                'status' => is_string($result['status'] ?? null) ? $result['status'] : null,
                'issues' => $this->redactor->messageList($result['issues'] ?? []),
                'messages' => $this->redactor->messageList($result['messages'] ?? []),
                'can_continue' => null !== $this->continuationFromResult($result),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    public function pollingPayload(array $state, int $cursor = 0): array
    {
        $entries = [];
        foreach (is_array($state['entries'] ?? null) ? $state['entries'] : [] as $entry) {
            if (is_array($entry) && (int) ($entry['cursor'] ?? 0) > $cursor) {
                $entries[] = $this->entry($entry);
            }
        }
        $status = (string) ($state['status'] ?? self::STATUS_QUEUED);
        $terminal = in_array($status, self::TERMINAL_STATUSES, true);

        return [
            'operation_id' => (string) $state['operation_id'],
            'operation' => (string) $state['operation'],
            'label' => (string) $state['label'],
            'status' => $status,
            'created_at' => $state['created_at'] ?? null,
            'updated_at' => $state['updated_at'] ?? null,
            'started_at' => $state['started_at'] ?? null,
            'finished_at' => $state['finished_at'] ?? null,
            'cursor' => (int) ($state['cursor'] ?? 0),
            'cursor_max' => (int) ($state['cursor'] ?? 0),
            'progress' => is_array($state['progress'] ?? null) ? $state['progress'] : ['index' => 0, 'total' => 0],
            'entries' => $entries,
            'result' => $terminal ? $this->result($state['result'] ?? null) : null,
            'can_continue' => $terminal && self::STATUS_REQUIRES_REVIEW === $status && null !== $this->continuationFromResult($state['result'] ?? null),
            'next_poll_ms' => $terminal ? null : 750,
        ];
    }

    /**
     * @return array{operation: string, payload: array<string, mixed>, label: string}|null
     */
    public function continuationFromResult(mixed $result): ?array
    {
        if (!is_array($result)) {
            return null;
        }

        $context = $result['context'] ?? null;
        $continuation = is_array($context) ? ($context['live_operation_continuation'] ?? null) : null;

        if (!is_array($continuation)) {
            return null;
        }

        $operation = $continuation['operation'] ?? null;
        $payload = $continuation['payload'] ?? [];
        $label = $continuation['label'] ?? null;

        if (!is_string($operation) || '' === trim($operation) || !is_array($payload)) {
            return null;
        }

        return [
            'operation' => trim($operation),
            'payload' => $payload,
            'label' => is_string($label) && '' !== trim($label) ? trim($label) : trim($operation),
        ];
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function entry(array $entry): array
    {
        $presentedEntry = [
            'cursor' => (int) ($entry['cursor'] ?? 0),
            'index' => (int) ($entry['index'] ?? 0),
            'total' => (int) ($entry['total'] ?? 0),
            'name' => (string) ($entry['name'] ?? ''),
            'status' => (string) ($entry['status'] ?? ''),
            'started_at' => $entry['started_at'] ?? null,
            'finished_at' => $entry['finished_at'] ?? null,
            'issues' => $this->redactor->messageList($entry['issues'] ?? []),
            'messages' => $this->redactor->messageList($entry['messages'] ?? []),
        ];

        $context = $this->entryContext($entry);
        if ([] !== $context) {
            $presentedEntry['context'] = $context;
        }

        return $presentedEntry;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function result(mixed $result): ?array
    {
        if (!is_array($result)) {
            return null;
        }

        return [
            'status' => is_string($result['status'] ?? null) ? $result['status'] : null,
            'issues' => $this->redactor->messageList($result['issues'] ?? []),
            'messages' => $this->redactor->messageList($result['messages'] ?? []),
            'can_continue' => null !== $this->continuationFromResult($result),
        ];
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function entryContext(array $entry): array
    {
        $status = is_string($entry['status'] ?? null) ? strtolower($entry['status']) : '';

        if (!isset(self::DIAGNOSTIC_ENTRY_STATUSES[$status]) || !is_array($entry['context'] ?? null)) {
            return [];
        }

        return $this->redactor->redact($entry['context']);
    }
}
