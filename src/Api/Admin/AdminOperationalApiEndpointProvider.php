<?php

declare(strict_types=1);

namespace App\Api\Admin;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointProviderInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class AdminOperationalApiEndpointProvider implements ApiEndpointProviderInterface
{
    public const HANDLER_BACKUPS = 'admin.backups';
    public const HANDLER_LOGS = 'admin.logs';
    public const HANDLER_OPERATIONS = 'admin.operations';
    public const HANDLER_SCHEDULER = 'admin.scheduler';
    public const HANDLER_STATISTICS = 'admin.statistics';
    public const HANDLER_THEMES = 'admin.themes';

    public function apiEndpoints(): array
    {
        return [
            $this->endpoint('/api/v1/admin/backups', 'listAdminBackups', 'List backup API capabilities prepared for future backup operations.', self::HANDLER_BACKUPS),
            $this->endpoint('/api/v1/admin/logs', 'listAdminLogs', 'List administrative log entries visible to administrators.', self::HANDLER_LOGS),
            $this->endpoint('/api/v1/admin/operations', 'listAdminOperations', 'List live operation runs visible to administrators.', self::HANDLER_OPERATIONS),
            $this->endpoint('/api/v1/admin/scheduler', 'listAdminSchedulerTasks', 'List scheduler tasks visible to administrators.', self::HANDLER_SCHEDULER),
            $this->endpoint('/api/v1/admin/statistics', 'getAdminStatistics', 'Return access statistics visible to administrators.', self::HANDLER_STATISTICS),
            $this->endpoint('/api/v1/admin/themes', 'listAdminThemes', 'List frontend and backend themes visible to administrators.', self::HANDLER_THEMES),
        ];
    }

    private function endpoint(string $path, string $operationId, string $summary, string $handler): ApiEndpointDefinition
    {
        return new ApiEndpointDefinition(
            'admin',
            Request::METHOD_GET,
            $path,
            'api_v1_endpoint_dispatch',
            $operationId,
            $summary,
            $handler,
            ['admin'],
            responseSchema: ['type' => 'object'],
        );
    }
}
