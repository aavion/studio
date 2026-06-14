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
        $candidates = array_values(array_filter(
            $this->endpoints(),
            static fn (LiveEndpointDefinition $endpoint): bool => $endpoint->matchesPath($path)
                && ($endpoint->method() === $method || ('HEAD' === $method && 'GET' === $endpoint->method())),
        ));
        usort($candidates, static function (LiveEndpointDefinition $left, LiveEndpointDefinition $right) use ($path): int {
            $leftExact = $left->path() === $path ? 1 : 0;
            $rightExact = $right->path() === $path ? 1 : 0;

            return [
                $rightExact,
                strlen($right->path()),
                $right->path(),
                $right->operationId(),
            ] <=> [
                $leftExact,
                strlen($left->path()),
                $left->path(),
                $left->operationId(),
            ];
        });

        return $candidates[0] ?? null;
    }
}
