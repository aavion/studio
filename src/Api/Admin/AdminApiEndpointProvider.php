<?php

declare(strict_types=1);

namespace App\Api\Admin;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointProviderInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class AdminApiEndpointProvider implements ApiEndpointProviderInterface
{
    public const HANDLER_ADMIN_INDEX = 'admin.index';

    public function apiEndpoints(): array
    {
        return [
            new ApiEndpointDefinition(
                'admin',
                Request::METHOD_GET,
                '/api/v1/admin',
                'api_v1_endpoint_dispatch',
                'listAdminApiEndpoints',
                'List administrative API endpoints visible to administrators.',
                self::HANDLER_ADMIN_INDEX,
                ['backend-admin'],
                responseSchema: [
                    'type' => 'object',
                    'properties' => [
                        'data' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'type' => ['type' => 'string'],
                                    'id' => ['type' => 'string'],
                                    'attributes' => ['type' => 'object'],
                                ],
                            ],
                        ],
                    ],
                ],
            ),
        ];
    }
}
