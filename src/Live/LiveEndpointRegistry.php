<?php

declare(strict_types=1);

namespace App\Live;

use Symfony\Component\HttpFoundation\Request;

final readonly class LiveEndpointRegistry
{
    /**
     * @param iterable<LiveEndpointProviderInterface> $providers
     */
    public function __construct(private iterable $providers)
    {
    }

    /**
     * @return list<LiveEndpointDefinition>
     */
    public function endpoints(): array
    {
        $endpoints = [];

        foreach ($this->providers as $provider) {
            array_push($endpoints, ...$provider->liveEndpoints());
        }

        usort(
            $endpoints,
            static fn (LiveEndpointDefinition $left, LiveEndpointDefinition $right): int => [
                $left->path(),
                $left->method(),
                $left->operationId(),
            ] <=> [
                $right->path(),
                $right->method(),
                $right->operationId(),
            ],
        );

        return $endpoints;
    }

    public function endpointForRequest(Request $request): ?LiveEndpointDefinition
    {
        return $this->endpointForPath($request->getPathInfo(), $request->getMethod());
    }

    public function endpointForPath(string $path, string $method): ?LiveEndpointDefinition
    {
        foreach ($this->endpoints() as $endpoint) {
            if (!$endpoint->matchesPath($path)) {
                continue;
            }

            if ($endpoint->method() === $method || ('HEAD' === $method && 'GET' === $endpoint->method())) {
                return $endpoint;
            }
        }

        return null;
    }
}
