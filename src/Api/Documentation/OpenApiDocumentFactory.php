<?php

declare(strict_types=1);

namespace App\Api\Documentation;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointRegistry;

final readonly class OpenApiDocumentFactory
{
    public function __construct(private ApiEndpointRegistry $endpoints)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Studio API',
                'version' => 'v1',
            ],
            'servers' => [
                ['url' => '/api/v1'],
            ],
            'security' => [
                ['bearerAuth' => []],
            ],
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
}
