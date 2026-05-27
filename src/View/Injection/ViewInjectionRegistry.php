<?php

declare(strict_types=1);

namespace App\View\Injection;

use App\Content\Read\PublishedContentView;
use App\Core\Event\PublicEventDispatcher;
use App\View\Injection\Event\DynamicViewInjectionRegistryEvent;
use App\View\Injection\Event\StaticViewInjectionRegistryEvent;

final readonly class ViewInjectionRegistry
{
    /**
     * @param iterable<StaticViewInjectionProviderInterface> $staticProviders
     * @param iterable<DynamicViewInjectionProviderInterface> $dynamicProviders
     */
    public function __construct(
        private iterable $staticProviders,
        private iterable $dynamicProviders,
        private PublicEventDispatcher $eventDispatcher,
    ) {
    }

    /**
     * @return list<StaticViewInjection>
     */
    public function staticInjections(?ViewSurface $surface = null, bool $menuOnly = false): array
    {
        $injections = [];

        foreach ($this->staticProviders as $provider) {
            array_push($injections, ...$provider->staticViewInjections());
        }

        $event = new StaticViewInjectionRegistryEvent($injections);
        $result = $this->eventDispatcher->dispatch($event, [
            'operation' => 'view.static_injection_registry',
            'surface' => $surface?->value,
        ]);

        $injections = $result->isSuccess() ? $event->injections() : $injections;
        $injections = $this->deduplicateStatic($injections, $surface);
        $injections = $this->enforceStaticParents($injections, $menuOnly);

        usort(
            $injections,
            static fn (StaticViewInjection $left, StaticViewInjection $right): int => [
                $left->surface()->value,
                $left->sortOrder(),
                $left->label(),
                $left->uid(),
            ] <=> [
                $right->surface()->value,
                $right->sortOrder(),
                $right->label(),
                $right->uid(),
            ],
        );

        return $injections;
    }

    public function findStatic(ViewSurface $surface, string $path): ?StaticViewInjection
    {
        $path = '/'.trim($path, '/');
        $path = '/' === $path ? '/' : rtrim($path, '/');

        foreach ($this->staticInjections($surface) as $injection) {
            if ($injection->routePath() === $path) {
                return $injection;
            }
        }

        return null;
    }

    /**
     * @return list<DynamicViewInjection>
     */
    public function dynamicInjections(
        ?ViewSurface $surface = null,
        ?DynamicViewInjectionSlot $slot = null,
        ?PublishedContentView $view = null,
    ): array {
        $injections = [];

        foreach ($this->dynamicProviders as $provider) {
            array_push($injections, ...$provider->dynamicViewInjections());
        }

        $event = new DynamicViewInjectionRegistryEvent($injections);
        $result = $this->eventDispatcher->dispatch($event, [
            'operation' => 'view.dynamic_injection_registry',
            'surface' => $surface?->value,
            'slot' => $slot?->value,
            'content_uid' => $view?->content()->uid(),
        ]);

        $injections = $result->isSuccess() ? $event->injections() : $injections;

        $injections = array_values(array_filter(
            $injections,
            static fn (DynamicViewInjection $injection): bool => (null === $surface || $injection->surface() === $surface)
                && (null === $slot || $injection->slot() === $slot)
                && (null === $view || $injection->filter()->matches($view)),
        ));

        usort(
            $injections,
            static fn (DynamicViewInjection $left, DynamicViewInjection $right): int => [
                $left->sortOrder(),
                $left->label() ?? '',
                $left->uid(),
            ] <=> [
                $right->sortOrder(),
                $right->label() ?? '',
                $right->uid(),
            ],
        );

        return $injections;
    }

    public function findDynamicRoute(ViewSurface $surface, PublishedContentView $view, string $variantSlug): ?DynamicViewInjection
    {
        foreach ($this->dynamicInjections($surface, DynamicViewInjectionSlot::Route, $view) as $injection) {
            if ($injection->variantSlug() === $variantSlug) {
                return $injection;
            }
        }

        return null;
    }

    /**
     * @param list<StaticViewInjection> $injections
     *
     * @return list<StaticViewInjection>
     */
    private function deduplicateStatic(array $injections, ?ViewSurface $surface): array
    {
        $seen = [];
        $resolved = [];

        foreach ($injections as $injection) {
            if (null !== $surface && $injection->surface() !== $surface) {
                continue;
            }

            $key = $injection->surface()->value.':'.$injection->routePath();

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $resolved[] = $injection;
        }

        return $resolved;
    }

    /**
     * @param list<StaticViewInjection> $injections
     *
     * @return list<StaticViewInjection>
     */
    private function enforceStaticParents(array $injections, bool $menuOnly): array
    {
        $known = [];

        foreach ($injections as $injection) {
            if ($menuOnly && !$injection->menuVisible()) {
                continue;
            }

            $known[$injection->surface()->value.':'.$injection->pathSlug()] = true;
        }

        return array_values(array_filter($injections, static function (StaticViewInjection $injection) use ($known, $menuOnly): bool {
            if ($menuOnly && !$injection->menuVisible()) {
                return false;
            }

            $parentSlug = $injection->parentSlug();

            if (null === $parentSlug) {
                return true;
            }

            return isset($known[$injection->surface()->value.':'.$parentSlug]);
        }));
    }
}
