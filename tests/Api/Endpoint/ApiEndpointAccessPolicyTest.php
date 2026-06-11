<?php

declare(strict_types=1);

namespace App\Tests\Api\Endpoint;

use App\Api\Endpoint\ApiEndpointAccessPolicy;
use App\Api\Endpoint\ApiEndpointDefinition;
use App\Core\Access\AccessLevel;
use PHPUnit\Framework\TestCase;

final class ApiEndpointAccessPolicyTest extends TestCase
{
    public function testItDerivesAccessRequirementsFromEndpointMetadata(): void
    {
        $policy = new ApiEndpointAccessPolicy();

        $public = new ApiEndpointDefinition(
            'system',
            'GET',
            '/api/v1/status',
            'api_v1_status',
            'getApiStatus',
            'Status.',
            tags: ['system-status'],
            allowPublic: true,
        );
        $admin = new ApiEndpointDefinition(
            'admin',
            'GET',
            '/api/v1/admin',
            'api_v1_endpoint_dispatch',
            'listAdminApiEndpoints',
            'Admin.',
            'admin.index',
            ['backend-admin'],
        );
        $schema = new ApiEndpointDefinition(
            'schemas',
            'GET',
            '/api/v1/schemas',
            'api_v1_endpoint_dispatch',
            'listContentSchemas',
            'Schemas.',
            'schemas.index',
            ['backend-editor', 'backend-editor-schemas'],
        );
        $profilePatch = new ApiEndpointDefinition(
            'user',
            'PATCH',
            '/api/v1/user',
            'api_v1_endpoint_dispatch',
            'updateCurrentUserProfile',
            'Profile update.',
            'user.self',
            ['frontend-user', 'frontend-user-profile'],
            requestSchema: ['type' => 'object'],
        );

        self::assertSame(AccessLevel::PUBLIC, $policy->minimumAccessLevel($public));
        self::assertSame('public', $policy->minimumRole($public));
        self::assertFalse($policy->requiresApiKey($public));
        self::assertSame(AccessLevel::ADMIN, $policy->minimumAccessLevel($admin));
        self::assertSame('admin', $policy->minimumRole($admin));
        self::assertSame(AccessLevel::AUTHOR, $policy->minimumAccessLevel($schema));
        self::assertSame('author', $policy->minimumRole($schema));
        self::assertSame(AccessLevel::USER, $policy->minimumAccessLevel($profilePatch));
        self::assertSame('read_write', $policy->keyCapability($profilePatch));
        self::assertTrue($policy->requiresApiKey($profilePatch));
    }

    public function testExplicitMinimumAccessLevelWinsOverTagDefaults(): void
    {
        $endpoint = new ApiEndpointDefinition(
            'system',
            'GET',
            '/api/v1/internal',
            'api_v1_endpoint_dispatch',
            'getInternal',
            'Internal.',
            'system.internal',
            ['system-api'],
            minimumAccessLevel: AccessLevel::OWNER,
        );

        self::assertSame(AccessLevel::OWNER, (new ApiEndpointAccessPolicy())->minimumAccessLevel($endpoint));
    }
}
