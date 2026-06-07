<?php

declare(strict_types=1);

namespace App\Api\Endpoint;

use Symfony\Component\HttpFoundation\Request;

final readonly class ApiEndpointRegistry
{
    /**
     * @param iterable<ApiEndpointProviderInterface> $providers
     */
    public function __construct(private iterable $providers)
    {
    }

    /**
     * @return list<ApiEndpointDefinition>
     */
    public function endpoints(): array
    {
        $endpoints = [];

        foreach ($this->providers as $provider) {
            array_push($endpoints, ...$provider->apiEndpoints());
        }

        usort(
            $endpoints,
            static fn (ApiEndpointDefinition $left, ApiEndpointDefinition $right): int => [
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

    public function endpointForRoute(string $routeName, string $method): ?ApiEndpointDefinition
    {
        foreach ($this->endpoints() as $endpoint) {
            if ($endpoint->routeName() !== $routeName) {
                continue;
            }

            if ($endpoint->method() === $method || ('HEAD' === $method && 'GET' === $endpoint->method())) {
                return $endpoint;
            }
        }

        return null;
    }

    public function endpointForRequest(Request $request): ?ApiEndpointDefinition
    {
        return $this->endpointForPath($request->getPathInfo(), $request->getMethod());
    }

    public function endpointForPath(string $path, string $method): ?ApiEndpointDefinition
    {
        foreach ($this->endpoints() as $endpoint) {
            if ($endpoint->path() !== $path) {
                continue;
            }

            if ($endpoint->method() === $method || ('HEAD' === $method && 'GET' === $endpoint->method())) {
                return $endpoint;
            }
        }

        return null;
    }
}
