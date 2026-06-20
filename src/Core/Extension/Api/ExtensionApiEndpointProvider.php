<?php

declare(strict_types=1);

namespace App\Core\Extension\Api;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointProviderInterface;
use App\Core\Extension\ExtensionIdentity;
use Symfony\Component\HttpFoundation\Request;

final readonly class ExtensionApiEndpointProvider implements ApiEndpointProviderInterface
{
    public const HANDLER_EXTENSIONS_INDEX = 'extensions.index';
    public const HANDLER_EXTENSIONS_NAVIGATION = 'extensions.navigation';
    private const EXTENSION_SEGMENT_PATTERN = ExtensionIdentity::EXTENSION_NAME_PATTERN;

    public function apiEndpoints(): array
    {
        return [
            new ApiEndpointDefinition(
                'extensions',
                Request::METHOD_GET,
                '/api/v1/extensions',
                'api_v1_endpoint_dispatch',
                'listExtensionApiEndpoints',
                'List extension API namespaces and available extension endpoint children.',
                self::HANDLER_EXTENSIONS_NAVIGATION,
                ['extensions-navigation'],
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
                'extensions',
                Request::METHOD_GET,
                '/api/v1/admin/extensions',
                'api_v1_endpoint_dispatch',
                'listExtensions',
                'List installed and discovered extensions visible to administrators.',
                self::HANDLER_EXTENSIONS_INDEX,
                ['backend-admin', 'backend-admin-extensions'],
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
                'extensions',
                Request::METHOD_GET,
                '/api/v1/admin/extensions/{extension_slug}',
                'api_v1_endpoint_dispatch',
                'getExtension',
                'Return one installed or discovered extension visible to administrators.',
                self::HANDLER_EXTENSIONS_INDEX,
                ['backend-admin', 'backend-admin-extensions'],
                parameters: $this->extensionParameters(),
                responseSchema: ['type' => 'object'],
                pathPattern: $this->extensionPattern(),
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
            $operationId = 'extension'.str_replace(' ', '', ucwords(str_replace('-', ' ', $action)));
            $endpoints[] = new ApiEndpointDefinition(
                'extensions',
                Request::METHOD_POST,
                '/api/v1/admin/extensions/{extension_slug}/'.$action,
                'api_v1_endpoint_dispatch',
                $operationId,
                sprintf('Review or confirm the "%s" lifecycle action for one extension.', $action),
                self::HANDLER_EXTENSIONS_INDEX,
                ['backend-admin', 'backend-admin-extensions'],
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
    private function extensionParameters(): array
    {
        return [
            ['name' => 'extension_slug', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'pattern' => '^'.self::EXTENSION_SEGMENT_PATTERN.'$', 'maxLength' => ExtensionIdentity::MAX_EXTENSION_NAME_LENGTH]],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lifecycleParameters(): array
    {
        return [
            ...$this->extensionParameters(),
            ['name' => 'confirm', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'boolean']],
        ];
    }

    private function extensionPattern(): string
    {
        return '#^/api/v1/admin/extensions/'.self::EXTENSION_SEGMENT_PATTERN.'$#';
    }

    private function lifecyclePattern(string $action): string
    {
        return '#^/api/v1/admin/extensions/'.self::EXTENSION_SEGMENT_PATTERN.'/'.$action.'$#';
    }
}
