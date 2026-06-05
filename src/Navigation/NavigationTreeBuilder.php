<?php

declare(strict_types=1);

namespace App\Navigation;

final readonly class NavigationTreeBuilder
{
    /**
     * @param list<NavigationItem> $items
     *
     * @return list<NavigationItem>
     */
    public function buildTree(array $items): array
    {
        $childrenByParent = [];

        foreach ($items as $item) {
            $childrenByParent[$item->parentUid() ?? ''][] = $item;
        }

        return $this->withChildren($childrenByParent[''] ?? [], $childrenByParent);
    }

    /**
     * @param list<NavigationItem> $items
     *
     * @return list<NavigationItem>
     */
    public function markActive(array $items, ?string $activeUrl, ?string $activeRoute): array
    {
        if (null === $activeUrl && null === $activeRoute) {
            return $items;
        }

        return array_map(function (NavigationItem $item) use ($activeUrl, $activeRoute): NavigationItem {
            $children = $this->markActive($item->children(), $activeUrl, $activeRoute);
            $active = $this->isActive($item, $activeUrl, $activeRoute);
            $activeAncestor = [] !== array_filter(
                $children,
                static fn (NavigationItem $child): bool => $child->isActive() || $child->isActiveAncestor(),
            );

            return $item->withChildren($children)->withActiveState($active, $activeAncestor);
        }, $items);
    }

    /**
     * @param list<NavigationItem> $tree
     *
     * @return list<NavigationItem>
     */
    public function slice(array $tree, int $maxDepth, int $startLevel, ?string $rootUid): array
    {
        if (null !== $rootUid) {
            $root = $this->findByUid($tree, $rootUid);

            return null === $root ? [] : $this->limitDepth($root->children(), $maxDepth);
        }

        if (1 < $startLevel) {
            return $this->limitDepth($this->itemsAtLevel($tree, $startLevel), $maxDepth);
        }

        return $this->limitDepth($tree, $maxDepth);
    }

    /**
     * @param list<NavigationItem> $items
     * @param array<string, list<NavigationItem>> $childrenByParent
     * @param array<string, true> $visited
     *
     * @return list<NavigationItem>
     */
    private function withChildren(array $items, array $childrenByParent, array $visited = []): array
    {
        $items = $this->sortItems($items);
        $tree = [];

        foreach ($items as $item) {
            if (isset($visited[$item->uid()])) {
                continue;
            }

            $tree[] = $item->withChildren(
                $this->withChildren(
                    $childrenByParent[$item->uid()] ?? [],
                    $childrenByParent,
                    $visited + [$item->uid() => true],
                ),
            );
        }

        return $tree;
    }

    /**
     * @param list<NavigationItem> $items
     *
     * @return list<NavigationItem>
     */
    private function sortItems(array $items): array
    {
        usort(
            $items,
            static fn (NavigationItem $left, NavigationItem $right): int => [
                $left->sortOrder(),
                $left->label(),
                $left->uid(),
            ] <=> [
                $right->sortOrder(),
                $right->label(),
                $right->uid(),
            ],
        );

        return $items;
    }

    private function isActive(NavigationItem $item, ?string $activeUrl, ?string $activeRoute): bool
    {
        $routeParameters = $item->metadata()['route_parameters'] ?? [];

        if (
            null !== $activeRoute
            && 'route' === $item->targetType()
            && $item->targetValue() === $activeRoute
            && ([] === $routeParameters || false === is_array($routeParameters))
        ) {
            return true;
        }

        return null !== $activeUrl && null !== $item->resolvedUrl() && rtrim($item->resolvedUrl(), '/') === rtrim($activeUrl, '/');
    }

    /**
     * @param list<NavigationItem> $items
     *
     * @return list<NavigationItem>
     */
    private function limitDepth(array $items, int $maxDepth): array
    {
        if (1 >= $maxDepth) {
            return array_map(
                static fn (NavigationItem $item): NavigationItem => $item->withChildren([]),
                $items,
            );
        }

        return array_map(
            fn (NavigationItem $item): NavigationItem => $item->withChildren(
                $this->limitDepth($item->children(), $maxDepth - 1),
            ),
            $items,
        );
    }

    /**
     * @param list<NavigationItem> $items
     *
     * @return list<NavigationItem>
     */
    private function itemsAtLevel(array $items, int $targetLevel, int $currentLevel = 1): array
    {
        if ($currentLevel === $targetLevel) {
            return $items;
        }

        $matches = [];

        foreach ($items as $item) {
            array_push($matches, ...$this->itemsAtLevel($item->children(), $targetLevel, $currentLevel + 1));
        }

        return $matches;
    }

    /**
     * @param list<NavigationItem> $items
     */
    private function findByUid(array $items, string $uid): ?NavigationItem
    {
        foreach ($items as $item) {
            if ($item->uid() === $uid) {
                return $item;
            }

            $match = $this->findByUid($item->children(), $uid);

            if (null !== $match) {
                return $match;
            }
        }

        return null;
    }
}
