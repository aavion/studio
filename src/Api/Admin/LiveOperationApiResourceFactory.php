<?php

declare(strict_types=1);

namespace App\Api\Admin;

final readonly class LiveOperationApiResourceFactory
{
    /**
     * @return array<string, string>
     */
    public function links(string $operationId, bool $canContinue = false): array
    {
        $links = [
            'self' => '/api/v1/admin/operations/'.$operationId,
            'status' => '/api/v1/admin/operations/'.$operationId,
        ];

        if ($canContinue) {
            $links['continue'] = '/api/v1/admin/operations/'.$operationId.'/continue';
        }

        return $links;
    }

    /**
     * @return array<string, string>
     */
    public function continuationLinks(string $operationId): array
    {
        return [
            ...$this->links($operationId, canContinue: true),
            'confirm' => '/api/v1/admin/operations/'.$operationId.'/continue?confirm=true',
        ];
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return array<string, mixed>
     */
    public function started(array $value): array
    {
        $operationId = (string) ($value['operation_id'] ?? '');

        return [
            'type' => 'operation_start',
            'id' => $operationId,
            'attributes' => [
                'operation_id' => $operationId,
                'operation' => (string) ($value['operation'] ?? ''),
                'label' => (string) ($value['label'] ?? ''),
                'status' => (string) ($value['status'] ?? 'queued'),
                'status_path' => '/api/v1/admin/operations/'.$operationId,
            ],
            'links' => $this->links($operationId),
        ];
    }
}
