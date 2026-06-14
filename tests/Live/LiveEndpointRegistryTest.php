<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Live\LiveEndpointDefinition;
use App\Live\LiveEndpointProviderInterface;
use App\Live\LiveEndpointRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class LiveEndpointRegistryTest extends TestCase
{
    public function testItPrefersExactEndpointBeforeMatchingPatternEndpoint(): void
    {
        $registry = new LiveEndpointRegistry([$this->provider([
            $this->endpoint(
                '/api/live/demo-pack/items',
                'listItems',
                'packages.demo-pack.live.items',
                '#^/api/live/demo-pack/items(?:/.*)?$#',
            ),
            $this->endpoint(
                '/api/live/demo-pack/items/special',
                'specialItems',
                'packages.demo-pack.live.items_special',
            ),
        ])]);

        $endpoint = $registry->endpointForPath('/api/live/demo-pack/items/special', Request::METHOD_GET);

        self::assertInstanceOf(LiveEndpointDefinition::class, $endpoint);
        self::assertSame('specialItems', $endpoint->operationId());
    }

    public function testItPrefersMoreSpecificPatternEndpoint(): void
    {
        $registry = new LiveEndpointRegistry([$this->provider([
            $this->endpoint(
                '/api/live/demo-pack/items',
                'listItems',
                'packages.demo-pack.live.items',
                '#^/api/live/demo-pack/items(?:/.*)?$#',
            ),
            $this->endpoint(
                '/api/live/demo-pack/items/special',
                'specialChildren',
                'packages.demo-pack.live.items_special_children',
                '#^/api/live/demo-pack/items/special(?:/.*)?$#',
            ),
        ])]);

        $endpoint = $registry->endpointForPath('/api/live/demo-pack/items/special/child', Request::METHOD_GET);

        self::assertInstanceOf(LiveEndpointDefinition::class, $endpoint);
        self::assertSame('specialChildren', $endpoint->operationId());
    }

    /**
     * @param list<LiveEndpointDefinition> $endpoints
     */
    private function provider(array $endpoints): LiveEndpointProviderInterface
    {
        return new readonly class($endpoints) implements LiveEndpointProviderInterface {
            /**
             * @param list<LiveEndpointDefinition> $endpoints
             */
            public function __construct(private array $endpoints)
            {
            }

            public function liveEndpoints(): array
            {
                return $this->endpoints;
            }
        };
    }

    private function endpoint(string $path, string $operationId, string $handlerKey, ?string $pathPattern = null): LiveEndpointDefinition
    {
        return new LiveEndpointDefinition(
            'package',
            Request::METHOD_GET,
            $path,
            'api_live_package_dispatch',
            $operationId,
            'Run a demo live endpoint.',
            $handlerKey,
            pathPattern: $pathPattern,
        );
    }
}
