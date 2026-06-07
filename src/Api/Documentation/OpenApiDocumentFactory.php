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
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                    ],
                ],
            ],
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
                                'schema' => $endpoint->responseSchema() ?? ['type' => 'object'],
                            ],
                        ],
                    ],
                    '401' => ['description' => 'API key authentication failed.'],
                    '403' => ['description' => 'The authenticated API key is not allowed to use this operation.'],
                ],
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
