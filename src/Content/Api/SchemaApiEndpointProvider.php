<?php

declare(strict_types=1);

namespace App\Content\Api;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointProviderInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class SchemaApiEndpointProvider implements ApiEndpointProviderInterface
{
    public const HANDLER_SCHEMAS_INDEX = 'schemas.index';

    public function apiEndpoints(): array
    {
        return [
            new ApiEndpointDefinition(
                'schemas',
                Request::METHOD_GET,
                '/api/v1/schemas',
                'api_v1_endpoint_dispatch',
                'listContentSchemas',
                'List active content schema metadata visible to API authors.',
                self::HANDLER_SCHEMAS_INDEX,
                ['schemas'],
                responseSchema: ['type' => 'object'],
            ),
        ];
    }
}
