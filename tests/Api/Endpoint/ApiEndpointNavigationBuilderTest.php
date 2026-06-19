<?php

declare(strict_types=1);

namespace App\Tests\Api\Endpoint;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointAccessPolicy;
use App\Api\Endpoint\ApiEndpointNavigationBuilder;
use App\Api\Endpoint\ApiEndpointProviderInterface;
use App\Api\Endpoint\ApiEndpointRegistry;
use PHPUnit\Framework\TestCase;

final class ApiEndpointNavigationBuilderTest extends TestCase
{
    public function testItListsDirectChildrenAndFiltersPrivateEndpoints(): void
    {
        $navigation = $this->builder()->navigation('/api/v1/extensions', includePrivate: false);

        self::assertSame('/api/v1/extensions', $navigation['id']);
        self::assertSame(['GET'], array_column($navigation['attributes']['methods'], 'method'));

        $children = $navigation['attributes']['children'];
        self::assertCount(1, $children);
        self::assertSame('/api/v1/extensions/public-module', $children[0]['attributes']['path']);
        self::assertSame(1, $children[0]['attributes']['child_count']);
    }

    public function testItIncludesPrivateChildrenForAuthenticatedNavigation(): void
    {
        $navigation = $this->builder()->navigation('/api/v1/extensions', includePrivate: true);
        $paths = array_map(
            static fn (array $child): string => $child['attributes']['path'],
            $navigation['attributes']['children'],
        );

        self::assertSame([
            '/api/v1/extensions/private-module',
            '/api/v1/extensions/public-module',
        ], $paths);
    }

    private function builder(): ApiEndpointNavigationBuilder
    {
        return new ApiEndpointNavigationBuilder(
            new ApiEndpointRegistry([
                new class implements ApiEndpointProviderInterface {
                    public function apiEndpoints(): array
                    {
                        return [
                            new ApiEndpointDefinition(
                                'extensions',
                                'GET',
                                '/api/v1/extensions',
                                'api_v1_endpoint_dispatch',
                                'listExtensionApiEndpoints',
                                'List extension endpoint children.',
                                'extensions.navigation',
                                allowPublic: true,
                            ),
                            new ApiEndpointDefinition(
                                'extension',
                                'GET',
                                '/api/v1/extensions/public-module/feed',
                                'api_v1_endpoint_dispatch',
                                'getPublicExtensionFeed',
                                'Return public extension feed.',
                                'extensions.public-module.feed',
                                allowPublic: true,
                            ),
                            new ApiEndpointDefinition(
                                'extension',
                                'GET',
                                '/api/v1/extensions/private-module/config',
                                'api_v1_endpoint_dispatch',
                                'getPrivateExtensionConfig',
                                'Return private extension configuration.',
                                'extensions.private-module.config',
                            ),
                        ];
                    }
                },
            ]),
            new ApiEndpointAccessPolicy(),
        );
    }
}
