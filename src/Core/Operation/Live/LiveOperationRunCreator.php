<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

final readonly class LiveOperationRunCreator
{
    private const STATUS_QUEUED = 'queued';

    public function __construct(
        private LiveOperationRunStorage $storage,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{operation_id: string, token: string, operation: string, label: string, status: string}
     */
    public function create(string $operation, array $payload, string $label): array
    {
        $operationId = bin2hex(random_bytes(16));
        $token = bin2hex(random_bytes(24));
        $state = [
            'operation_id' => $operationId,
            'token' => $token,
            'operation' => $operation,
            'label' => $label,
            'payload' => $payload,
            'status' => self::STATUS_QUEUED,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
            'started_at' => null,
            'finished_at' => null,
            'cursor' => 0,
            'progress' => ['index' => 0, 'total' => 0],
            'entries' => [],
            'result' => null,
        ];

        $this->storage->write($operationId, $state);

        return [
            'operation_id' => $operationId,
            'token' => $token,
            'operation' => $operation,
            'label' => $label,
            'status' => self::STATUS_QUEUED,
        ];
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(DATE_ATOM);
    }
}
