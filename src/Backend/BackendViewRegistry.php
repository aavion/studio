<?php

declare(strict_types=1);

namespace App\Backend;

final readonly class BackendViewRegistry
{
    /**
     * @param iterable<BackendViewProviderInterface> $providers
     */
    public function __construct(private iterable $providers)
    {
    }

    public function find(BackendArea $area, string $path = ''): ?BackendViewDefinition
    {
        $path = trim($path, '/');

        foreach ($this->views($area) as $view) {
            if ($view->path() === $path) {
                return $view;
            }
        }

        return null;
    }

    /**
     * @return list<BackendViewDefinition>
     */
    public function views(?BackendArea $area = null): array
    {
        $views = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->backendViews() as $view) {
                if (null === $area || $view->area() === $area) {
                    $views[] = $view;
                }
            }
        }

        usort(
            $views,
            static fn (BackendViewDefinition $left, BackendViewDefinition $right): int => [
                $left->area()->value,
                $left->sortOrder(),
                $left->label(),
                $left->uid(),
            ] <=> [
                $right->area()->value,
                $right->sortOrder(),
                $right->label(),
                $right->uid(),
            ],
        );

        return $views;
    }
}
