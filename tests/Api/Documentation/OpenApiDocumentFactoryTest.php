<?php

declare(strict_types=1);

namespace App\Tests\Api\Documentation;

use App\Api\Documentation\OpenApiDocumentFactory;
use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointProviderInterface;
use App\Api\Endpoint\ApiEndpointRegistry;
use App\View\SystemPackageMetadataProvider;
use PHPUnit\Framework\TestCase;

final class OpenApiDocumentFactoryTest extends TestCase
{
    public function testItUsesSystemPackageManifestNameForApiTitle(): void
    {
        $projectDir = sys_get_temp_dir().'/studio-openapi-manifest-'.bin2hex(random_bytes(4));
        mkdir($projectDir);
        file_put_contents($projectDir.'/.manifest', implode("\n", [
            'APP_NAME=Example Product',
            'APP_DESCRIPTION=Example API documentation.',
            'APP_LICENSE=MIT',
            '',
        ]));

        try {
            $document = (new OpenApiDocumentFactory(
                new ApiEndpointRegistry([$this->provider()]),
                new SystemPackageMetadataProvider($projectDir),
            ))->create();
        } finally {
            unlink($projectDir.'/.manifest');
            rmdir($projectDir);
        }

        self::assertSame('3.2.0', $document['openapi']);
        self::assertSame('/api/v1/openapi.json', $document['$self']);
        self::assertSame('Example Product API', $document['info']['title']);
        self::assertSame('Example Product API', $document['info']['summary']);
        self::assertSame('Example API documentation.', $document['info']['description']);
        self::assertSame(['name' => 'MIT', 'identifier' => 'MIT'], $document['info']['license']);
        self::assertSame([['name' => 'current', 'url' => '/api/v1']], $document['servers']);
        self::assertContains(['name' => 'system-status', 'summary' => 'System Status', 'description' => 'Status and healthcheck resources.', 'kind' => 'nav'], $document['tags']);
    }

    public function testItEmitsOpenApi32TagMetadataForUsedEndpointTags(): void
    {
        $document = (new OpenApiDocumentFactory(
            new ApiEndpointRegistry([$this->provider()]),
            new SystemPackageMetadataProvider(dirname(__DIR__, 3)),
        ))->create();

        self::assertContains([
            'name' => 'backend-admin',
            'summary' => 'Backend Admin',
            'description' => 'Backend administration resources.',
            'kind' => 'nav',
        ], $document['tags']);
        self::assertContains([
            'name' => 'backend-admin-users',
            'summary' => 'Backend Admin Users',
            'description' => 'Administrative user, ACL group, and review resources.',
            'parent' => 'backend-admin',
            'kind' => 'nav',
        ], $document['tags']);
        self::assertNotContains([
            'name' => 'users',
            'summary' => 'Users',
            'kind' => 'nav',
        ], $document['tags']);
        self::assertContains([
            'name' => 'custom_tag',
            'summary' => 'Custom tag',
            'kind' => 'nav',
        ], $document['tags']);
    }

    private function provider(): ApiEndpointProviderInterface
    {
        return new class implements ApiEndpointProviderInterface {
            public function apiEndpoints(): array
            {
                return [
                    new ApiEndpointDefinition(
                        'system',
                        'GET',
                        '/api/v1/status',
                        'api_v1_status',
                        'getApiStatus',
                        'Status.',
                        tags: ['system-status'],
                        allowPublic: true,
                    ),
                    new ApiEndpointDefinition(
                        'users',
                        'GET',
                        '/api/v1/admin/users',
                        'api_v1_endpoint_dispatch',
                        'listUsers',
                        'List users.',
                        tags: ['backend-admin', 'backend-admin-users', 'custom_tag'],
                    ),
                ];
            }
        };
    }
}
