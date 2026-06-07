<?php

declare(strict_types=1);

namespace App\Security\Api;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointProviderInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class UserApiEndpointProvider implements ApiEndpointProviderInterface
{
    public const HANDLER_USERS_INDEX = 'users.index';
    public const HANDLER_USER_GROUPS_INDEX = 'users.groups.index';
    public const HANDLER_USER_REVIEWS_INDEX = 'users.reviews.index';

    public function apiEndpoints(): array
    {
        return [
            new ApiEndpointDefinition(
                'users',
                Request::METHOD_GET,
                '/api/v1/admin/users',
                'api_v1_endpoint_dispatch',
                'listUsers',
                'List user accounts visible to administrators.',
                self::HANDLER_USERS_INDEX,
                ['admin', 'users'],
                parameters: [
                    ['name' => 'q', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                    ['name' => 'status', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                    ['name' => 'role', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                    ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                    ['name' => 'per_page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                ],
                responseSchema: ['type' => 'object'],
            ),
            new ApiEndpointDefinition(
                'users',
                Request::METHOD_GET,
                '/api/v1/admin/users/groups',
                'api_v1_endpoint_dispatch',
                'listUserGroups',
                'List ACL groups visible to administrators.',
                self::HANDLER_USER_GROUPS_INDEX,
                ['admin', 'users'],
                parameters: [
                    ['name' => 'q', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                    ['name' => 'sort', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                    ['name' => 'direction', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                    ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                    ['name' => 'per_page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                ],
                responseSchema: ['type' => 'object'],
            ),
            new ApiEndpointDefinition(
                'users',
                Request::METHOD_GET,
                '/api/v1/admin/users/reviews',
                'api_v1_endpoint_dispatch',
                'listUserReviews',
                'List pending user review items visible to administrators.',
                self::HANDLER_USER_REVIEWS_INDEX,
                ['admin', 'users'],
                parameters: [
                    ['name' => 'filter', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                    ['name' => 'q', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                    ['name' => 'sort', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                    ['name' => 'direction', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                    ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                    ['name' => 'per_page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                ],
                responseSchema: ['type' => 'object'],
            ),
        ];
    }
}
