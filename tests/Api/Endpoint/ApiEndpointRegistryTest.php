<?php

declare(strict_types=1);

namespace App\Tests\Api\Endpoint;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointProviderInterface;
use App\Api\Endpoint\ApiEndpointRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ApiEndpointRegistryTest extends TestCase
{
    public function testItPrefersExactEndpointBeforeMatchingPatternEndpoint(): void
    {
        $registry = new ApiEndpointRegistry([$this->provider([
            $this->endpoint(
                '/api/v1/content/items',
                'listItems',
                'system.api.items',
                '#^/api/v1/content/items(?:/.*)?$#',
            ),
            $this->endpoint(
                '/api/v1/content/items/special',
                'specialItems',
                'system.api.items_special',
            ),
        ])]);

        $endpoint = $registry->endpointForPath('/api/v1/content/items/special', Request::METHOD_GET);

        self::assertInstanceOf(ApiEndpointDefinition::class, $endpoint);
        self::assertSame('specialItems', $endpoint->operationId());
    }

    public function testItPrefersMoreSpecificPatternEndpoint(): void
    {
        $registry = new ApiEndpointRegistry([$this->provider([
            $this->endpoint(
                '/api/v1/content/items',
                'listItems',
                'system.api.items',
                '#^/api/v1/content/items(?:/.*)?$#',
            ),
            $this->endpoint(
                '/api/v1/content/items/special',
                'specialChildren',
                'system.api.items_special_children',
                '#^/api/v1/content/items/special(?:/.*)?$#',
            ),
        ])]);

        $endpoint = $registry->endpointForPath('/api/v1/content/items/special/child', Request::METHOD_GET);

        self::assertInstanceOf(ApiEndpointDefinition::class, $endpoint);
        self::assertSame('specialChildren', $endpoint->operationId());
    }

    /**
     * @param list<ApiEndpointDefinition> $endpoints
     */
    private function provider(array $endpoints): ApiEndpointProviderInterface
    {
        return new readonly class($endpoints) implements ApiEndpointProviderInterface {
            /**
             * @param list<ApiEndpointDefinition> $endpoints
             */
            public function __construct(private array $endpoints)
            {
            }

            public function apiEndpoints(): array
            {
                return $this->endpoints;
            }
        };
    }

    private function endpoint(string $path, string $operationId, string $handlerKey, ?string $pathPattern = null): ApiEndpointDefinition
    {
        return new ApiEndpointDefinition(
            'system',
            Request::METHOD_GET,
            $path,
            'api_v1_endpoint_dispatch',
            $operationId,
            'Run a demo API endpoint.',
            $handlerKey,
            pathPattern: $pathPattern,
        );
    }
}
