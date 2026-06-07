<?php

declare(strict_types=1);

namespace App\Api\Endpoint;

final readonly class ApiEndpointNavigationBuilder
{
    public function __construct(private ApiEndpointRegistry $endpoints)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function navigation(string $path, bool $includePrivate): array
    {
        $path = $this->normalizePath($path);
        $children = [];
        $methods = [];

        foreach ($this->visibleEndpoints($includePrivate) as $endpoint) {
            $endpointPath = $this->normalizePath($endpoint->path());

            if ($endpointPath === $path) {
                $methods[] = $this->operation($endpoint);
                continue;
            }

            if (!str_starts_with($endpointPath, $path.'/')) {
                continue;
            }

            $remainder = substr($endpointPath, strlen($path) + 1);
            $segment = explode('/', $remainder, 2)[0];
            $childPath = $path.'/'.$segment;
            $children[$childPath] ??= [
                'type' => 'api_navigation_link',
                'id' => $childPath,
                'attributes' => [
                    'path' => $childPath,
                    'segment' => $segment,
                    'methods' => [],
                    'child_count' => 0,
                ],
            ];

            if ($endpointPath === $childPath) {
                $children[$childPath]['attributes']['methods'][] = $this->operation($endpoint);
            } else {
                ++$children[$childPath]['attributes']['child_count'];
            }
        }

        return [
            'type' => 'api_navigation',
            'id' => $path,
            'attributes' => [
                'path' => $path,
                'methods' => $methods,
                'children' => array_values($children),
            ],
        ];
    }

    /**
     * @return list<ApiEndpointDefinition>
     */
    private function visibleEndpoints(bool $includePrivate): array
    {
        return array_values(array_filter(
            $this->endpoints->endpoints(),
            static fn (ApiEndpointDefinition $endpoint): bool => $includePrivate || $endpoint->allowsPublic(),
        ));
    }

    /**
     * @return array<string, string|list<string>>
     */
    private function operation(ApiEndpointDefinition $endpoint): array
    {
        return [
            'method' => $endpoint->method(),
            'operation_id' => $endpoint->operationId(),
            'summary' => $endpoint->summary(),
            'tags' => $endpoint->tags(),
        ];
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.trim($path, '/');

        return '/' === $path ? $path : rtrim($path, '/');
    }
}
