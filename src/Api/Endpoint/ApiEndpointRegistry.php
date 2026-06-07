<?php

declare(strict_types=1);

namespace App\Api\Endpoint;

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
}
