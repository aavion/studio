<?php

declare(strict_types=1);

namespace App\Backend;

use App\View\Injection\StaticViewInjection;
use App\View\Injection\ViewInjectionRegistry;
use App\View\Injection\ViewSurface;

final readonly class BackendViewRegistry
{
    /**
     * @param iterable<BackendViewProviderInterface> $providers
     */
    public function __construct(
        private iterable $providers,
        private ViewInjectionRegistry $viewInjections,
    ) {
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

        foreach ($this->staticViews($views, $area) as $view) {
            $views[] = $view;
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

    /**
     * @param list<BackendViewDefinition> $existing
     *
     * @return list<BackendViewDefinition>
     */
    private function staticViews(array $existing, ?BackendArea $area): array
    {
        $surfaces = match ($area) {
            BackendArea::Admin => [ViewSurface::Admin],
            BackendArea::Editor => [ViewSurface::Editor],
            BackendArea::Setup => [],
            null => [ViewSurface::Admin, ViewSurface::Editor],
        };
        $knownPaths = [];

        foreach ($existing as $view) {
            $knownPaths[$view->area()->value.':'.$view->path()] = true;
        }

        $views = [];

        foreach ($surfaces as $surface) {
            foreach ($this->viewInjections->staticInjections($surface) as $injection) {
                $viewArea = $this->areaForSurface($injection->surface());

                if (!$viewArea instanceof BackendArea) {
                    continue;
                }

                $key = $viewArea->value.':'.$injection->pathSlug();

                if (isset($knownPaths[$key])) {
                    continue;
                }

                $knownPaths[$key] = true;
                $views[] = $this->definitionFromInjection($injection, $viewArea);
            }
        }

        return $views;
    }

    private function areaForSurface(ViewSurface $surface): ?BackendArea
    {
        return match ($surface) {
            ViewSurface::Admin => BackendArea::Admin,
            ViewSurface::Editor => BackendArea::Editor,
            ViewSurface::Public => null,
        };
    }

    private function definitionFromInjection(StaticViewInjection $injection, BackendArea $area): BackendViewDefinition
    {
        return new BackendViewDefinition(
            $injection->uid(),
            $area,
            $injection->pathSlug(),
            $injection->label(),
            $injection->template(),
            $injection->sortOrder(),
            parentUid: $this->parentUid($injection),
            minimumAccessLevel: $injection->accessLevel(),
            linkAttributes: $injection->linkAttributes(),
            accessGroups: $injection->accessGroups(),
        );
    }

    private function parentUid(StaticViewInjection $injection): ?string
    {
        $parentSlug = $injection->parentSlug();

        if (null === $parentSlug) {
            return null;
        }

        foreach ($this->viewInjections->staticInjections($injection->surface(), menuOnly: true) as $candidate) {
            if ($candidate->pathSlug() === $parentSlug) {
                return $candidate->uid();
            }
        }

        return null;
    }
}
