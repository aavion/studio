<?php

declare(strict_types=1);

namespace App\Core\Config\Api;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointProviderInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class SettingsApiEndpointProvider implements ApiEndpointProviderInterface
{
    public const HANDLER_SETTINGS_INDEX = 'settings.index';

    public function apiEndpoints(): array
    {
        return [
            new ApiEndpointDefinition(
                'settings',
                Request::METHOD_GET,
                '/api/v1/admin/settings',
                'api_v1_endpoint_dispatch',
                'listSettings',
                'List administrative settings visible to the API caller.',
                self::HANDLER_SETTINGS_INDEX,
                ['admin', 'settings'],
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
                        'meta' => [
                            'type' => 'object',
                            'properties' => [
                                'count' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                ],
            ),
        ];
    }
}
