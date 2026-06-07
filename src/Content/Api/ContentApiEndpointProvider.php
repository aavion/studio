<?php

declare(strict_types=1);

namespace App\Content\Api;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointProviderInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class ContentApiEndpointProvider implements ApiEndpointProviderInterface
{
    public const HANDLER_CONTENT_INDEX = 'content.index';
    public const HANDLER_CONTENT_ITEMS = 'content.items';

    public function apiEndpoints(): array
    {
        return [
            new ApiEndpointDefinition('content', Request::METHOD_GET, '/api/v1/content', 'api_v1_endpoint_dispatch', 'listContentApiEndpoints', 'List content API children.', self::HANDLER_CONTENT_INDEX, ['content'], allowPublic: true),
            new ApiEndpointDefinition('content', Request::METHOD_GET, '/api/v1/content/items', 'api_v1_endpoint_dispatch', 'listPublishedContentItems', 'List public published content item metadata.', self::HANDLER_CONTENT_ITEMS, ['content'], responseSchema: ['type' => 'object'], allowPublic: true),
        ];
    }
}
