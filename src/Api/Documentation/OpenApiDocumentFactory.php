<?php

declare(strict_types=1);

namespace App\Api\Documentation;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointRegistry;
use App\View\SystemPackageMetadataProvider;

final readonly class OpenApiDocumentFactory
{
    public function __construct(
        private ApiEndpointRegistry $endpoints,
        private SystemPackageMetadataProvider $systemPackageMetadata,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(): array
    {
        return [
            'openapi' => '3.2.0',
            '$self' => '/api/v1/openapi.json',
            'info' => [
                'title' => $this->apiTitle(),
                'summary' => $this->apiTitle(),
                ...$this->optionalInfo(),
                'version' => 'v1',
            ],
            'servers' => [
                [
                    'name' => 'current',
                    'url' => '/api/v1',
                ],
            ],
            'security' => [
                ['bearerAuth' => []],
            ],
            'tags' => $this->tags(),
            'paths' => $this->paths(),
            'components' => $this->components(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paths(): array
    {
        $paths = [];

        foreach ($this->endpoints->endpoints() as $endpoint) {
            $successStatus = (string) $endpoint->successStatus();
            $paths[$this->relativePath($endpoint)][$this->method($endpoint)] = [
                'operationId' => $endpoint->operationId(),
                'summary' => $endpoint->summary(),
                'tags' => $endpoint->tags(),
                'parameters' => $endpoint->parameters(),
                'responses' => [
                    $successStatus => [
                        'description' => 'Successful response.',
                        'content' => [
                            'application/json' => [
                                'schema' => $endpoint->responseSchema() ?? ['$ref' => '#/components/schemas/ApiDataEnvelope'],
                            ],
                        ],
                    ],
                ] + $this->standardErrorResponses(),
            ];

            if ($endpoint->allowsPublic()) {
                $paths[$this->relativePath($endpoint)][$this->method($endpoint)]['security'] = [];
            }

            if (null !== $endpoint->requestSchema()) {
                $paths[$this->relativePath($endpoint)][$this->method($endpoint)]['requestBody'] = [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => $endpoint->requestSchema(),
                        ],
                    ],
                ];
            }
        }

        return $paths;
    }

    /**
     * @return array<string, mixed>
     */
    private function components(): array
    {
        return [
            'securitySchemes' => [
                'bearerAuth' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                ],
            ],
            'schemas' => $this->schemas(),
            'responses' => $this->responses(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schemas(): array
    {
        return [
            'ApiDataEnvelope' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => true,
                    'meta' => ['$ref' => '#/components/schemas/ApiMeta'],
                    'links' => ['$ref' => '#/components/schemas/ApiLinks'],
                ],
                'additionalProperties' => false,
            ],
            'ApiErrorEnvelope' => [
                'type' => 'object',
                'required' => ['error'],
                'properties' => [
                    'error' => ['$ref' => '#/components/schemas/ApiError'],
                ],
                'additionalProperties' => false,
            ],
            'ApiError' => [
                'type' => 'object',
                'required' => ['status', 'code', 'message_key', 'message'],
                'properties' => [
                    'status' => ['type' => 'integer', 'minimum' => 400, 'maximum' => 599],
                    'code' => ['type' => 'string'],
                    'message_key' => ['type' => 'string'],
                    'message' => ['type' => 'string'],
                    'parameters' => ['type' => 'object', 'additionalProperties' => true],
                    'context' => ['type' => 'object', 'additionalProperties' => true],
                    'details' => ['type' => 'object', 'additionalProperties' => true],
                ],
                'additionalProperties' => false,
            ],
            'ApiMessage' => [
                'type' => 'object',
                'required' => ['level', 'code', 'translation_key', 'message', 'parameters', 'context'],
                'properties' => [
                    'level' => ['type' => 'string', 'enum' => ['debug', 'info', 'success', 'warning', 'error']],
                    'code' => ['type' => 'string'],
                    'translation_key' => ['type' => 'string'],
                    'message' => ['type' => 'string'],
                    'parameters' => ['type' => 'object', 'additionalProperties' => true],
                    'context' => ['type' => 'object', 'additionalProperties' => true],
                ],
                'additionalProperties' => false,
            ],
            'ApiMeta' => [
                'type' => 'object',
                'additionalProperties' => true,
                'properties' => [
                    'pagination' => ['$ref' => '#/components/schemas/ApiPagination'],
                    'messages' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/ApiMessage'],
                    ],
                ],
            ],
            'ApiPagination' => [
                'type' => 'object',
                'properties' => [
                    'page' => ['type' => 'integer', 'minimum' => 1],
                    'per_page' => ['type' => 'integer', 'minimum' => 1],
                    'total' => ['type' => 'integer', 'minimum' => 0],
                    'page_count' => ['type' => 'integer', 'minimum' => 0],
                ],
                'additionalProperties' => false,
            ],
            'ApiLinks' => [
                'type' => 'object',
                'additionalProperties' => ['type' => 'string'],
            ],
            'ApiMutationReview' => [
                'type' => 'object',
                'required' => ['type', 'id', 'attributes'],
                'properties' => [
                    'type' => ['type' => 'string'],
                    'id' => ['type' => 'string'],
                    'attributes' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string', 'enum' => ['ok', 'warn', 'fail', 'requires_confirmation']],
                            'confirm_parameter' => ['type' => 'string'],
                            'impact' => ['type' => 'object', 'additionalProperties' => true],
                            'diff' => ['type' => 'object', 'additionalProperties' => true],
                        ],
                        'additionalProperties' => true,
                    ],
                    'links' => ['$ref' => '#/components/schemas/ApiLinks'],
                ],
                'additionalProperties' => false,
            ],
            'ApiOperationStart' => [
                'type' => 'object',
                'required' => ['type', 'id', 'attributes', 'links'],
                'properties' => [
                    'type' => ['type' => 'string'],
                    'id' => ['type' => 'string'],
                    'attributes' => [
                        'type' => 'object',
                        'properties' => [
                            'operation_id' => ['type' => 'string'],
                            'status' => ['type' => 'string'],
                        ],
                        'additionalProperties' => true,
                    ],
                    'links' => ['$ref' => '#/components/schemas/ApiLinks'],
                ],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function responses(): array
    {
        return [
            'BadRequest' => $this->errorResponse('The request body or parameters are invalid.'),
            'Unauthorized' => $this->errorResponse('API key authentication failed.'),
            'Forbidden' => $this->errorResponse('The authenticated actor is not allowed to use this operation.'),
            'NotFound' => $this->errorResponse('The requested API resource does not exist.'),
            'Conflict' => $this->errorResponse('The requested operation conflicts with the current resource state.'),
            'ValidationFailed' => $this->errorResponse('The request did not pass validation.'),
            'ServiceUnavailable' => $this->errorResponse('The API is temporarily unavailable.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResponse(string $description): array
    {
        return [
            'description' => $description,
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/ApiErrorEnvelope'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function standardErrorResponses(): array
    {
        return [
            '400' => ['$ref' => '#/components/responses/BadRequest'],
            '401' => ['$ref' => '#/components/responses/Unauthorized'],
            '403' => ['$ref' => '#/components/responses/Forbidden'],
            '404' => ['$ref' => '#/components/responses/NotFound'],
            '409' => ['$ref' => '#/components/responses/Conflict'],
            '422' => ['$ref' => '#/components/responses/ValidationFailed'],
            '503' => ['$ref' => '#/components/responses/ServiceUnavailable'],
        ];
    }

    private function relativePath(ApiEndpointDefinition $endpoint): string
    {
        return substr($endpoint->path(), strlen('/api/v1'));
    }

    private function method(ApiEndpointDefinition $endpoint): string
    {
        return strtolower($endpoint->method());
    }

    private function apiTitle(): string
    {
        $name = trim((string) $this->systemPackageMetadata->metadata()['name']);

        return ('' !== $name ? $name : 'System').' API';
    }

    /**
     * @return array<string, mixed>
     */
    private function optionalInfo(): array
    {
        $metadata = $this->systemPackageMetadata->metadata();
        $info = [];

        if (is_string($metadata['description'] ?? null) && '' !== trim($metadata['description'])) {
            $info['description'] = trim($metadata['description']);
        }

        if (is_string($metadata['license'] ?? null) && '' !== trim($metadata['license'])) {
            $license = trim($metadata['license']);
            $info['license'] = ['name' => $license];

            if (1 === preg_match('/^[A-Za-z0-9][A-Za-z0-9.+-]*$/', $license)) {
                $info['license']['identifier'] = $license;
            }
        }

        return $info;
    }

    /**
     * @return list<array<string, string>>
     */
    private function tags(): array
    {
        $used = [];
        foreach ($this->endpoints->endpoints() as $endpoint) {
            foreach ($endpoint->tags() as $tag) {
                $used[$tag] = true;
            }
        }

        $tags = [];
        foreach ($this->tagMetadata() as $name => $metadata) {
            if (!isset($used[$name])) {
                continue;
            }

            $tags[] = ['name' => $name, ...$metadata];
            unset($used[$name]);
        }

        foreach (array_keys($used) as $name) {
            $tags[] = [
                'name' => $name,
                'summary' => ucfirst(str_replace(['-', '_'], ' ', $name)),
                'kind' => 'nav',
            ];
        }

        return $tags;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function tagMetadata(): array
    {
        return [
            'backend-admin' => ['summary' => 'Backend Admin', 'description' => 'Backend administration resources.', 'kind' => 'nav'],
            'backend-admin-backups' => ['summary' => 'Backend Admin Backups', 'description' => 'Administrative backup capabilities and future backup operations.', 'parent' => 'backend-admin', 'kind' => 'nav'],
            'backend-admin-logs' => ['summary' => 'Backend Admin Logs', 'description' => 'Administrative log source and log entry resources.', 'parent' => 'backend-admin', 'kind' => 'nav'],
            'backend-admin-operations' => ['summary' => 'Backend Admin Operations', 'description' => 'Administrative live-operation status, continuation, and maintenance resources.', 'parent' => 'backend-admin', 'kind' => 'nav'],
            'backend-admin-packages' => ['summary' => 'Backend Admin Packages', 'description' => 'Administrative package management and lifecycle resources.', 'parent' => 'backend-admin', 'kind' => 'nav'],
            'backend-admin-scheduler' => ['summary' => 'Backend Admin Scheduler', 'description' => 'Administrative scheduler task and run resources.', 'parent' => 'backend-admin', 'kind' => 'nav'],
            'backend-admin-settings' => ['summary' => 'Backend Admin Settings', 'description' => 'Administrative settings sections and values.', 'parent' => 'backend-admin', 'kind' => 'nav'],
            'backend-admin-statistics' => ['summary' => 'Backend Admin Statistics', 'description' => 'Administrative access statistics resources.', 'parent' => 'backend-admin', 'kind' => 'nav'],
            'backend-admin-themes' => ['summary' => 'Backend Admin Themes', 'description' => 'Administrative frontend and backend theme resources.', 'parent' => 'backend-admin', 'kind' => 'nav'],
            'backend-admin-users' => ['summary' => 'Backend Admin Users', 'description' => 'Administrative user, ACL group, and review resources.', 'parent' => 'backend-admin', 'kind' => 'nav'],
            'backend-editor' => ['summary' => 'Backend Editor', 'description' => 'Backend editor resources for schema and structured content authoring.', 'kind' => 'nav'],
            'backend-editor-schemas' => ['summary' => 'Backend Editor Schemas', 'description' => 'Content schema metadata available to API authors.', 'parent' => 'backend-editor', 'kind' => 'nav'],
            'frontend-content' => ['summary' => 'Frontend Content', 'description' => 'Content item navigation, reads, and prepared mutation commands.', 'kind' => 'nav'],
            'frontend-content-items' => ['summary' => 'Frontend Content Items', 'description' => 'Content item resources and child, variant, revision, and mutation navigation.', 'parent' => 'frontend-content', 'kind' => 'nav'],
            'packages-navigation' => ['summary' => 'Package Navigation', 'description' => 'Package API namespaces and registered package endpoint navigation. Package contribution tags should use packages-{package_slug}-*.', 'kind' => 'nav'],
            'system-api' => ['summary' => 'System API', 'description' => 'API documentation and API metadata resources.', 'kind' => 'nav'],
            'system-status' => ['summary' => 'System Status', 'description' => 'Status and healthcheck resources.', 'kind' => 'nav'],
        ];
    }
}
