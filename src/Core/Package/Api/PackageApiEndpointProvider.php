<?php

declare(strict_types=1);

namespace App\Core\Package\Api;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointProviderInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class PackageApiEndpointProvider implements ApiEndpointProviderInterface
{
    public const HANDLER_PACKAGES_INDEX = 'packages.index';
    public const HANDLER_PACKAGES_NAVIGATION = 'packages.navigation';

    public function apiEndpoints(): array
    {
        return [
            new ApiEndpointDefinition(
                'packages',
                Request::METHOD_GET,
                '/api/v1/packages',
                'api_v1_endpoint_dispatch',
                'listPackageApiEndpoints',
                'List package API namespaces and available package endpoint children.',
                self::HANDLER_PACKAGES_NAVIGATION,
                ['packages'],
                responseSchema: [
                    'type' => 'object',
                    'properties' => [
                        'data' => [
                            'type' => 'object',
                            'properties' => [
                                'type' => ['type' => 'string'],
                                'id' => ['type' => 'string'],
                                'attributes' => ['type' => 'object'],
                            ],
                        ],
                    ],
                ],
                allowPublic: true,
            ),
            new ApiEndpointDefinition(
                'packages',
                Request::METHOD_GET,
                '/api/v1/admin/packages',
                'api_v1_endpoint_dispatch',
                'listPackages',
                'List installed and discovered extension packages visible to administrators.',
                self::HANDLER_PACKAGES_INDEX,
                ['admin', 'packages'],
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
