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
                'listSettingsSections',
                'List administrative settings sections visible to the API caller.',
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
            new ApiEndpointDefinition(
                'settings',
                Request::METHOD_GET,
                '/api/v1/admin/settings/{section}',
                'api_v1_endpoint_dispatch',
                'listSettingsSection',
                'List administrative settings for one settings section.',
                self::HANDLER_SETTINGS_INDEX,
                ['admin', 'settings'],
                parameters: [
                    ['name' => 'section', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                ],
                responseSchema: ['type' => 'object'],
                pathPattern: '#^/api/v1/admin/settings/[a-z0-9][a-z0-9_-]*$#',
            ),
        ];
    }
}
