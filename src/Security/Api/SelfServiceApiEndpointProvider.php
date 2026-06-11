<?php

declare(strict_types=1);

namespace App\Security\Api;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointProviderInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class SelfServiceApiEndpointProvider implements ApiEndpointProviderInterface
{
    public const HANDLER_USER_SELF = 'user.self';

    public function apiEndpoints(): array
    {
        return [
            new ApiEndpointDefinition(
                'user',
                Request::METHOD_GET,
                '/api/v1/user',
                'api_v1_endpoint_dispatch',
                'getCurrentUserProfile',
                'Return the authenticated user profile and self-service links.',
                self::HANDLER_USER_SELF,
                ['frontend-user', 'frontend-user-profile'],
                responseSchema: ['type' => 'object'],
            ),
            new ApiEndpointDefinition(
                'user',
                Request::METHOD_PATCH,
                '/api/v1/user',
                'api_v1_endpoint_dispatch',
                'updateCurrentUserProfile',
                'Validate and update the authenticated user profile.',
                self::HANDLER_USER_SELF,
                ['frontend-user', 'frontend-user-profile'],
                requestSchema: [
                    'type' => 'object',
                    'properties' => [
                        'username' => ['type' => 'string'],
                        'email' => ['type' => 'string', 'format' => 'email'],
                        'display_name' => ['type' => 'string'],
                        'language' => ['type' => 'string'],
                    ],
                    'additionalProperties' => false,
                ],
                responseSchema: ['type' => 'object'],
            ),
            new ApiEndpointDefinition(
                'user',
                Request::METHOD_GET,
                '/api/v1/user/api-keys',
                'api_v1_endpoint_dispatch',
                'listCurrentUserApiKeys',
                'List API keys owned by the authenticated user.',
                self::HANDLER_USER_SELF,
                ['frontend-user', 'frontend-user-api-keys'],
                responseSchema: ['type' => 'object'],
            ),
            new ApiEndpointDefinition(
                'user',
                Request::METHOD_POST,
                '/api/v1/user/api-keys',
                'api_v1_endpoint_dispatch',
                'createCurrentUserApiKey',
                'Create an API key for the authenticated user and return the plain key once.',
                self::HANDLER_USER_SELF,
                ['frontend-user', 'frontend-user-api-keys'],
                requestSchema: [
                    'type' => 'object',
                    'required' => ['prefix'],
                    'properties' => [
                        'prefix' => ['type' => 'string', 'minLength' => 4, 'maxLength' => 16, 'pattern' => '^[A-Za-z0-9_-]{4,16}$'],
                        'read_only' => ['type' => 'boolean', 'default' => true],
                    ],
                    'additionalProperties' => false,
                ],
                responseSchema: ['type' => 'object'],
                successStatus: 201,
            ),
            new ApiEndpointDefinition(
                'user',
                Request::METHOD_DELETE,
                '/api/v1/user/api-keys/items/{key_uid}',
                'api_v1_endpoint_dispatch',
                'revokeCurrentUserApiKey',
                'Revoke one API key owned by the authenticated user.',
                self::HANDLER_USER_SELF,
                ['frontend-user', 'frontend-user-api-keys'],
                parameters: [
                    ['name' => 'key_uid', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']],
                ],
                responseSchema: ['type' => 'object'],
                pathPattern: '#^/api/v1/user/api-keys/items/[a-f0-9-]{36}$#',
            ),
        ];
    }
}
