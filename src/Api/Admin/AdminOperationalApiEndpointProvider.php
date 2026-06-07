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
            $this->endpoint('/api/v1/admin/logs', 'listAdminLogSources', 'List administrative log sources visible to administrators.', self::HANDLER_LOGS),
            $this->endpoint('/api/v1/admin/logs/{log}', 'listAdminLogEntries', 'List administrative log entries for one log source.', self::HANDLER_LOGS, parameters: $this->logParameters(), pathPattern: '#^/api/v1/admin/logs/[a-z]+$#'),
            $this->endpoint('/api/v1/admin/operations', 'listAdminOperations', 'List live operation runs visible to administrators.', self::HANDLER_OPERATIONS),
            $this->endpoint('/api/v1/admin/operations/{operation_id}', 'getAdminOperation', 'Return one live operation report visible to administrators.', self::HANDLER_OPERATIONS, parameters: $this->operationParameters(), pathPattern: '#^/api/v1/admin/operations/[a-f0-9]{32}$#'),
            $this->endpoint('/api/v1/admin/operations/{operation_id}/continue', 'continueAdminOperation', 'Continue a review-gated live operation after explicit confirmation.', self::HANDLER_OPERATIONS, Request::METHOD_POST, parameters: $this->confirmOperationParameters(), responseSchema: ['type' => 'object'], successStatus: 202, pathPattern: '#^/api/v1/admin/operations/[a-f0-9]{32}/continue$#'),
            $this->endpoint('/api/v1/admin/scheduler', 'listAdminSchedulerTasks', 'List scheduler tasks visible to administrators.', self::HANDLER_SCHEDULER),
            $this->endpoint('/api/v1/admin/statistics', 'getAdminStatistics', 'Return access statistics visible to administrators.', self::HANDLER_STATISTICS),
            $this->endpoint('/api/v1/admin/themes', 'listAdminThemes', 'List frontend and backend themes visible to administrators.', self::HANDLER_THEMES),
        ];
    }

    /**
     * @param list<array<string, mixed>> $parameters
     * @param array<string, mixed>|null $responseSchema
     */
    private function endpoint(
        string $path,
        string $operationId,
        string $summary,
        string $handler,
        string $method = Request::METHOD_GET,
        array $parameters = [],
        ?array $responseSchema = null,
        int $successStatus = 200,
        ?string $pathPattern = null,
    ): ApiEndpointDefinition {
        return new ApiEndpointDefinition(
            'admin',
            $method,
            $path,
            'api_v1_endpoint_dispatch',
            $operationId,
            $summary,
            $handler,
            ['admin'],
            parameters: $parameters,
            responseSchema: $responseSchema ?? ['type' => 'object'],
            successStatus: $successStatus,
            pathPattern: $pathPattern,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function logParameters(): array
    {
        return [
            ['name' => 'log', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['application', 'message', 'audit', 'access']]],
            ['name' => 'level', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
            ['name' => 'q', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
            ['name' => 'match', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['contains', 'equals']]],
            ['name' => 'time_window', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['1h', '24h', '7d', '30d']]],
            ['name' => 'audit_action', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
            ['name' => 'per_page', 'in' => 'query', 'required' => false, 'schema' => [
                'oneOf' => [
                    ['type' => 'integer'],
                    ['type' => 'string', 'enum' => ['all']],
                ],
            ]],
            ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1]],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function operationParameters(): array
    {
        return [
            ['name' => 'operation_id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$']],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function confirmOperationParameters(): array
    {
        return [
            ...$this->operationParameters(),
            ['name' => 'confirm', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'boolean']],
        ];
    }
}
