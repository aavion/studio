<?php

declare(strict_types=1);

namespace App\Security\AutoBan\Api;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointProviderInterface;
use App\Core\Access\AccessLevel;
use Symfony\Component\HttpFoundation\Request;

final readonly class AutoBanApiEndpointProvider implements ApiEndpointProviderInterface
{
    public const HANDLER_AUTO_BANS = 'security.auto_bans';

    public function apiEndpoints(): array
    {
        return [
            $this->endpoint(
                Request::METHOD_GET,
                '/api/v1/admin/security/auto-bans',
                'listAdminSecurityAutoBans',
                'List active security auto-bans visible to Owners.',
            ),
            $this->endpoint(
                Request::METHOD_GET,
                '/api/v1/admin/security/auto-bans/{key}',
                'getAdminSecurityAutoBan',
                'Return one active security auto-ban and related security signals.',
                parameters: $this->keyParameters(),
                pathPattern: '#^/api/v1/admin/security/auto-bans/[a-f0-9]{40}$#',
            ),
            $this->endpoint(
                Request::METHOD_POST,
                '/api/v1/admin/security/auto-bans/{key}/reset',
                'resetAdminSecurityAutoBan',
                'Release one active security auto-ban and record the reset signal.',
                parameters: $this->keyParameters(),
                responseSchema: ['type' => 'object'],
                pathPattern: '#^/api/v1/admin/security/auto-bans/[a-f0-9]{40}/reset$#',
            ),
        ];
    }

    /**
     * @param list<array<string, mixed>> $parameters
     * @param array<string, mixed>|null $responseSchema
     */
    private function endpoint(
        string $method,
        string $path,
        string $operationId,
        string $summary,
        array $parameters = [],
        ?array $responseSchema = null,
        ?string $pathPattern = null,
    ): ApiEndpointDefinition {
        return new ApiEndpointDefinition(
            'security',
            $method,
            $path,
            'api_v1_endpoint_dispatch',
            $operationId,
            $summary,
            self::HANDLER_AUTO_BANS,
            ['backend-admin', 'backend-admin-security'],
            parameters: $parameters,
            responseSchema: $responseSchema ?? ['type' => 'object'],
            minimumAccessLevel: AccessLevel::OWNER,
            pathPattern: $pathPattern,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function keyParameters(): array
    {
        return [
            ['name' => 'key', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'pattern' => '^[a-f0-9]{40}$']],
        ];
    }
}
