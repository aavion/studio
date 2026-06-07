<?php

declare(strict_types=1);

namespace App\Api\Endpoint;

use Symfony\Component\HttpFoundation\Request;

final readonly class CoreApiEndpointProvider implements ApiEndpointProviderInterface
{
    public function apiEndpoints(): array
    {
        return [
            new ApiEndpointDefinition(
                'system',
                Request::METHOD_GET,
                '/api/v1/openapi.json',
                'api_v1_openapi',
                'getOpenApiDocument',
                'Return the OpenAPI document generated from registered API endpoint definitions.',
                ['system'],
                allowPublic: true,
            ),
            new ApiEndpointDefinition(
                'system',
                Request::METHOD_GET,
                '/api/v1/status',
                'api_v1_status',
                'getApiStatus',
                'Return a small authenticated API status payload.',
                ['system'],
                allowPublic: true,
            ),
        ];
    }
}
