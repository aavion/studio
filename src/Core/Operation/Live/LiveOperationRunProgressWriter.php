<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use App\Core\ActionLog\ActionLogEntry;

final readonly class LiveOperationRunProgressWriter
{
    private const STATUS_RUNNING = 'running';
    private const STATUS_SUCCESS = 'success';
    private const STATUS_REQUIRES_REVIEW = 'requires_review';
    private const STATUS_FAILED = 'failed';

    public function __construct(
        private LiveOperationRunStorage $storage,
    ) {
    }

    public function markRunning(string $operationId, int $total = 0): void
    {
        $this->storage->mutate($operationId, static function (array $state) use ($total): array {
            $state['status'] = self::STATUS_RUNNING;
            $state['started_at'] ??= (new \DateTimeImmutable())->format(DATE_ATOM);
            $state['progress'] = ['index' => 0, 'total' => max(0, $total)];

            return $state;
        });
    }

    public function setTotal(string $operationId, int $total): void
    {
        $this->storage->mutate($operationId, static function (array $state) use ($total): array {
            $progress = is_array($state['progress'] ?? null) ? $state['progress'] : [];
            $state['progress'] = [
                'index' => (int) ($progress['index'] ?? 0),
                'total' => max(0, $total),
            ];

            return $state;
        });
    }

    public function appendEntry(string $operationId, ActionLogEntry $entry, int $index, int $total): void
    {
        $this->storage->mutate($operationId, function (array $state) use ($entry, $index, $total): array {
            $cursor = ((int) ($state['cursor'] ?? 0)) + 1;
            $state['cursor'] = $cursor;
            $entries = is_array($state['entries'] ?? null) ? $state['entries'] : [];
            $entries[] = [
                'cursor' => $cursor,
                'index' => $index,
                'total' => $total,
                ...$entry->toArray(),
            ];
            $state['entries'] = $entries;
            $state['progress'] = ['index' => $index, 'total' => $total];

            return $state;
        });
    }

    /**
     * @param array<string, mixed> $result
     */
    public function finish(string $operationId, bool $success, array $result): void
    {
        $this->storage->mutate($operationId, static function (array $state) use ($success, $result): array {
            $state['status'] = self::STATUS_REQUIRES_REVIEW === ($result['status'] ?? null)
                ? self::STATUS_REQUIRES_REVIEW
                : ($success ? self::STATUS_SUCCESS : self::STATUS_FAILED);
            $state['finished_at'] = (new \DateTimeImmutable())->format(DATE_ATOM);
            $state['result'] = $result;

            return $state;
        });
    }
}
