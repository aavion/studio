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
    private const PACKAGE_SEGMENT_PATTERN = '[^/]+';

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
                ['packages-navigation'],
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
                ['backend-admin', 'backend-admin-packages'],
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
            new ApiEndpointDefinition(
                'packages',
                Request::METHOD_GET,
                '/api/v1/admin/packages/{package_slug}',
                'api_v1_endpoint_dispatch',
                'getPackage',
                'Return one installed or discovered extension package visible to administrators.',
                self::HANDLER_PACKAGES_INDEX,
                ['backend-admin', 'backend-admin-packages'],
                parameters: $this->packageParameters(),
                responseSchema: ['type' => 'object'],
                pathPattern: $this->packagePattern(),
            ),
            ...$this->lifecycleEndpoints(),
        ];
    }

    /**
     * @return list<ApiEndpointDefinition>
     */
    private function lifecycleEndpoints(): array
    {
        $endpoints = [];

        foreach (['activate', 'deactivate', 'reset-fault', 'delete', 'purge'] as $action) {
            $operationId = 'package'.str_replace(' ', '', ucwords(str_replace('-', ' ', $action)));
            $endpoints[] = new ApiEndpointDefinition(
                'packages',
                Request::METHOD_POST,
                '/api/v1/admin/packages/{package_slug}/'.$action,
                'api_v1_endpoint_dispatch',
                $operationId,
                sprintf('Review or confirm the "%s" lifecycle action for one extension package.', $action),
                self::HANDLER_PACKAGES_INDEX,
                ['backend-admin', 'backend-admin-packages'],
                parameters: $this->lifecycleParameters(),
                responseSchema: ['type' => 'object'],
                successStatus: 202,
                pathPattern: $this->lifecyclePattern($action),
            );
        }

        return $endpoints;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function packageParameters(): array
    {
        return [
            ['name' => 'package_slug', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lifecycleParameters(): array
    {
        return [
            ...$this->packageParameters(),
            ['name' => 'confirm', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'boolean']],
        ];
    }

    private function packagePattern(): string
    {
        return '#^/api/v1/admin/packages/'.self::PACKAGE_SEGMENT_PATTERN.'$#';
    }

    private function lifecyclePattern(string $action): string
    {
        return '#^/api/v1/admin/packages/'.self::PACKAGE_SEGMENT_PATTERN.'/'.$action.'$#';
    }
}
