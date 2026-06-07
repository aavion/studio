<?php

declare(strict_types=1);

namespace App\Tests\Api\Endpoint;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointNavigationBuilder;
use App\Api\Endpoint\ApiEndpointProviderInterface;
use App\Api\Endpoint\ApiEndpointRegistry;
use PHPUnit\Framework\TestCase;

final class ApiEndpointNavigationBuilderTest extends TestCase
{
    public function testItListsDirectChildrenAndFiltersPrivateEndpoints(): void
    {
        $navigation = $this->builder()->navigation('/api/v1/packages', includePrivate: false);

        self::assertSame('/api/v1/packages', $navigation['id']);
        self::assertSame(['GET'], array_column($navigation['attributes']['methods'], 'method'));

        $children = $navigation['attributes']['children'];
        self::assertCount(1, $children);
        self::assertSame('/api/v1/packages/public-module', $children[0]['attributes']['path']);
        self::assertSame(1, $children[0]['attributes']['child_count']);
    }

    public function testItIncludesPrivateChildrenForAuthenticatedNavigation(): void
    {
        $navigation = $this->builder()->navigation('/api/v1/packages', includePrivate: true);
        $paths = array_map(
            static fn (array $child): string => $child['attributes']['path'],
            $navigation['attributes']['children'],
        );

        self::assertSame([
            '/api/v1/packages/private-module',
            '/api/v1/packages/public-module',
        ], $paths);
    }

    private function builder(): ApiEndpointNavigationBuilder
    {
        return new ApiEndpointNavigationBuilder(new ApiEndpointRegistry([
            new class implements ApiEndpointProviderInterface {
                public function apiEndpoints(): array
                {
                    return [
                        new ApiEndpointDefinition(
                            'packages',
                            'GET',
                            '/api/v1/packages',
                            'api_v1_endpoint_dispatch',
                            'listPackageApiEndpoints',
                            'List package endpoint children.',
                            'packages.navigation',
                            allowPublic: true,
                        ),
                        new ApiEndpointDefinition(
                            'package',
                            'GET',
                            '/api/v1/packages/public-module/feed',
                            'api_v1_endpoint_dispatch',
                            'getPublicPackageFeed',
                            'Return public package feed.',
                            'packages.public-module.feed',
                            allowPublic: true,
                        ),
                        new ApiEndpointDefinition(
                            'package',
                            'GET',
                            '/api/v1/packages/private-module/config',
                            'api_v1_endpoint_dispatch',
                            'getPrivatePackageConfig',
                            'Return private package configuration.',
                            'packages.private-module.config',
                        ),
                    ];
                }
            },
        ]));
    }
}
