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
    public const HANDLER_USER_GROUP_MEMBERSHIPS = 'users.groups.memberships';
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
                ['backend-admin', 'backend-admin-users'],
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
                '/api/v1/admin/users/items/{username}',
                'api_v1_endpoint_dispatch',
                'getUser',
                'Return one user account visible to administrators.',
                self::HANDLER_USERS_INDEX,
                ['backend-admin', 'backend-admin-users'],
                parameters: [
                    ['name' => 'username', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                ],
                responseSchema: ['type' => 'object'],
                pathPattern: '#^/api/v1/admin/users/items/[A-Za-z][A-Za-z0-9_-]{4,29}$#',
            ),
            new ApiEndpointDefinition(
                'users',
                Request::METHOD_PATCH,
                '/api/v1/admin/users/items/{username}',
                'api_v1_endpoint_dispatch',
                'updateUser',
                'Validate and update status, role, and group assignments for one user account.',
                self::HANDLER_USERS_INDEX,
                ['backend-admin', 'backend-admin-users'],
                parameters: [
                    ['name' => 'username', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                ],
                requestSchema: [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string'],
                        'role' => ['type' => 'string'],
                        'groups' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
                responseSchema: ['type' => 'object'],
                pathPattern: '#^/api/v1/admin/users/items/[A-Za-z][A-Za-z0-9_-]{4,29}$#',
            ),
            new ApiEndpointDefinition(
                'users',
                Request::METHOD_GET,
                '/api/v1/admin/users/groups',
                'api_v1_endpoint_dispatch',
                'listUserGroups',
                'List ACL groups visible to administrators.',
                self::HANDLER_USER_GROUPS_INDEX,
                ['backend-admin', 'backend-admin-users'],
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
                Request::METHOD_POST,
                '/api/v1/admin/users/groups',
                'api_v1_endpoint_dispatch',
                'createUserGroup',
                'Validate and create one ACL group.',
                self::HANDLER_USER_GROUPS_INDEX,
                ['backend-admin', 'backend-admin-users'],
                requestSchema: [
                    'type' => 'object',
                    'required' => ['identifier', 'name', 'min_role'],
                    'properties' => [
                        'identifier' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
                        'min_role' => ['type' => 'integer'],
                    ],
                ],
                responseSchema: ['type' => 'object'],
                successStatus: 201,
            ),
            new ApiEndpointDefinition(
                'users',
                Request::METHOD_GET,
                '/api/v1/admin/users/groups/items/{group_identifier}',
                'api_v1_endpoint_dispatch',
                'getUserGroup',
                'Return one ACL group, its members, and its current impact.',
                self::HANDLER_USER_GROUPS_INDEX,
                ['backend-admin', 'backend-admin-users'],
                parameters: [
                    ['name' => 'group_identifier', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                ],
                responseSchema: ['type' => 'object'],
                pathPattern: '#^/api/v1/admin/users/groups/items/[a-z][a-z0-9_]{2,79}$#',
            ),
            new ApiEndpointDefinition(
                'users',
                Request::METHOD_PATCH,
                '/api/v1/admin/users/groups/items/{group_identifier}',
                'api_v1_endpoint_dispatch',
                'updateUserGroup',
                'Review or confirm an ACL group update.',
                self::HANDLER_USER_GROUPS_INDEX,
                ['backend-admin', 'backend-admin-users'],
                parameters: [
                    ['name' => 'group_identifier', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ['name' => 'confirm', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'boolean']],
                ],
                requestSchema: [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'min_role' => ['type' => 'integer'],
                        'live_operation' => ['type' => 'boolean'],
                    ],
                ],
                responseSchema: ['type' => 'object'],
                pathPattern: '#^/api/v1/admin/users/groups/items/[a-z][a-z0-9_]{2,79}$#',
            ),
            new ApiEndpointDefinition(
                'users',
                Request::METHOD_DELETE,
                '/api/v1/admin/users/groups/items/{group_identifier}',
                'api_v1_endpoint_dispatch',
                'deleteUserGroup',
                'Review or confirm ACL group deletion and reference cleanup.',
                self::HANDLER_USER_GROUPS_INDEX,
                ['backend-admin', 'backend-admin-users'],
                parameters: [
                    ['name' => 'group_identifier', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ['name' => 'confirm', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'boolean']],
                ],
                requestSchema: [
                    'type' => 'object',
                    'properties' => [
                        'live_operation' => ['type' => 'boolean'],
                    ],
                ],
                responseSchema: ['type' => 'object'],
                pathPattern: '#^/api/v1/admin/users/groups/items/[a-z][a-z0-9_]{2,79}$#',
            ),
            new ApiEndpointDefinition(
                'users',
                Request::METHOD_POST,
                '/api/v1/admin/users/items/{username}/groups/{group_identifier}',
                'api_v1_endpoint_dispatch',
                'addUserGroupMembership',
                'Add one ACL group to one user account.',
                self::HANDLER_USER_GROUP_MEMBERSHIPS,
                ['backend-admin', 'backend-admin-users'],
                parameters: $this->membershipParameters(),
                responseSchema: ['type' => 'object'],
                pathPattern: '#^/api/v1/admin/users/items/[A-Za-z][A-Za-z0-9_-]{4,29}/groups/[a-z][a-z0-9_]{2,79}$#',
            ),
            new ApiEndpointDefinition(
                'users',
                Request::METHOD_DELETE,
                '/api/v1/admin/users/items/{username}/groups/{group_identifier}',
                'api_v1_endpoint_dispatch',
                'removeUserGroupMembership',
                'Remove one ACL group from one user account.',
                self::HANDLER_USER_GROUP_MEMBERSHIPS,
                ['backend-admin', 'backend-admin-users'],
                parameters: $this->membershipParameters(),
                responseSchema: ['type' => 'object'],
                pathPattern: '#^/api/v1/admin/users/items/[A-Za-z][A-Za-z0-9_-]{4,29}/groups/[a-z][a-z0-9_]{2,79}$#',
            ),
            new ApiEndpointDefinition(
                'users',
                Request::METHOD_GET,
                '/api/v1/admin/users/reviews',
                'api_v1_endpoint_dispatch',
                'listUserReviews',
                'List pending user review items visible to administrators.',
                self::HANDLER_USER_REVIEWS_INDEX,
                ['backend-admin', 'backend-admin-users'],
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
            new ApiEndpointDefinition(
                'users',
                Request::METHOD_POST,
                '/api/v1/admin/users/reviews/items/{username}/reactivate',
                'api_v1_endpoint_dispatch',
                'reactivateUserReview',
                'Review or confirm reactivation of a user account pending security review.',
                self::HANDLER_USER_REVIEWS_INDEX,
                ['backend-admin', 'backend-admin-users'],
                parameters: $this->reviewActionParameters(),
                responseSchema: ['type' => 'object'],
                pathPattern: '#^/api/v1/admin/users/reviews/items/[A-Za-z][A-Za-z0-9_-]{4,29}/reactivate$#',
            ),
            new ApiEndpointDefinition(
                'users',
                Request::METHOD_DELETE,
                '/api/v1/admin/users/reviews/items/{username}',
                'api_v1_endpoint_dispatch',
                'denyUserReview',
                'Review or confirm denial of a user security review by deleting the account.',
                self::HANDLER_USER_REVIEWS_INDEX,
                ['backend-admin', 'backend-admin-users'],
                parameters: $this->reviewActionParameters(),
                responseSchema: ['type' => 'object'],
                pathPattern: '#^/api/v1/admin/users/reviews/items/[A-Za-z][A-Za-z0-9_-]{4,29}$#',
            ),
            new ApiEndpointDefinition(
                'users',
                Request::METHOD_POST,
                '/api/v1/admin/users/reviews/tokens/{token_uid}/approve',
                'api_v1_endpoint_dispatch',
                'approveUserRegistrationReview',
                'Review or confirm approval of a pending self-registration token.',
                self::HANDLER_USER_REVIEWS_INDEX,
                ['backend-admin', 'backend-admin-users'],
                parameters: $this->tokenReviewActionParameters(),
                responseSchema: ['type' => 'object'],
                pathPattern: '#^/api/v1/admin/users/reviews/tokens/[a-f0-9-]{36}/approve$#',
            ),
            new ApiEndpointDefinition(
                'users',
                Request::METHOD_POST,
                '/api/v1/admin/users/reviews/tokens/{token_uid}/reissue',
                'api_v1_endpoint_dispatch',
                'reissueUserAccountTokenReview',
                'Review or confirm reissuing an open account token.',
                self::HANDLER_USER_REVIEWS_INDEX,
                ['backend-admin', 'backend-admin-users'],
                parameters: $this->tokenReviewActionParameters(),
                responseSchema: ['type' => 'object'],
                pathPattern: '#^/api/v1/admin/users/reviews/tokens/[a-f0-9-]{36}/reissue$#',
            ),
            new ApiEndpointDefinition(
                'users',
                Request::METHOD_DELETE,
                '/api/v1/admin/users/reviews/tokens/{token_uid}',
                'api_v1_endpoint_dispatch',
                'denyUserAccountTokenReview',
                'Review or confirm revocation or denial of an open account token.',
                self::HANDLER_USER_REVIEWS_INDEX,
                ['backend-admin', 'backend-admin-users'],
                parameters: $this->tokenReviewActionParameters(),
                responseSchema: ['type' => 'object'],
                pathPattern: '#^/api/v1/admin/users/reviews/tokens/[a-f0-9-]{36}$#',
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function membershipParameters(): array
    {
        return [
            ['name' => 'username', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
            ['name' => 'group_identifier', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reviewActionParameters(): array
    {
        return [
            ['name' => 'username', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
            ['name' => 'confirm', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'boolean']],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tokenReviewActionParameters(): array
    {
        return [
            ['name' => 'token_uid', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']],
            ['name' => 'confirm', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'boolean']],
        ];
    }
}
