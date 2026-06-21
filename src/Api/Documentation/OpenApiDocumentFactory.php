<?php

declare(strict_types=1);

namespace App\Api\Documentation;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointAccessPolicy;
use App\Api\Endpoint\ApiEndpointRegistry;
use App\View\SystemExtensionMetadataProvider;

final readonly class OpenApiDocumentFactory
{
    public function __construct(
        private ApiEndpointRegistry $endpoints,
        private ApiEndpointAccessPolicy $accessPolicy,
        private SystemExtensionMetadataProvider $systemExtensionMetadata,
        private ?OpenApiComponentsFactory $componentsFactory = null,
        private ?OpenApiTagFactory $tagFactory = null,
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
            'components' => $this->components()->components(),
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
                'x-access' => [
                    'allow_public' => $endpoint->allowsPublic(),
                    'requires_api_key' => $this->accessPolicy->requiresApiKey($endpoint),
                    'required_access_level' => $this->accessPolicy->minimumAccessLevel($endpoint),
                    'required_role' => $this->accessPolicy->minimumRole($endpoint),
                    'key_capability' => $this->accessPolicy->keyCapability($endpoint),
                ],
                'responses' => [
                    $successStatus => [
                        'description' => 'Successful response.',
                        'headers' => $this->traceHeaders(),
                        'content' => [
                            'application/json' => [
                                'schema' => $endpoint->responseSchema() ?? ['$ref' => '#/components/schemas/ApiDataEnvelope'],
                            ],
                        ],
                    ],
                ] + $this->components()->standardErrorResponses(),
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
    private function components(): OpenApiComponentsFactory
    {
        return $this->componentsFactory ?? new OpenApiComponentsFactory();
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function traceHeaders(): array
    {
        return $this->components()->traceHeaders();
    }

    private function relativePath(ApiEndpointDefinition $endpoint): string
    {
        if ('/api/v1' === $endpoint->path()) {
            return '/';
        }

        return substr($endpoint->path(), strlen('/api/v1'));
    }

    private function method(ApiEndpointDefinition $endpoint): string
    {
        return strtolower($endpoint->method());
    }

    private function apiTitle(): string
    {
        $name = trim((string) $this->systemExtensionMetadata->metadata()['name']);

        return ('' !== $name ? $name : 'System').' API';
    }

    /**
     * @return array<string, mixed>
     */
    private function optionalInfo(): array
    {
        $metadata = $this->systemExtensionMetadata->metadata();
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
        return ($this->tagFactory ?? new OpenApiTagFactory($this->endpoints))->tags();
    }
}
