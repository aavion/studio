<?php

declare(strict_types=1);

namespace App\Navigation;

use App\Core\Access\AccessActor;
use App\Core\Event\PublicEventDispatcher;
use App\Navigation\Event\NavigationBuilderEvent;
use Doctrine\DBAL\Connection;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

final readonly class NavigationBuilder
{
    public function __construct(
        private Connection $connection,
        private PublicEventDispatcher $eventDispatcher,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function build(
        string $identifier = 'main',
        string $language = 'en',
        int $maxDepth = 3,
        int $startLevel = 1,
        ?string $rootUid = null,
        ?AccessActor $actor = null,
        ?string $activeUrl = null,
        ?string $activeRoute = null,
    ): array {
        $maxDepth = max(1, $maxDepth);
        $startLevel = max(1, $startLevel);
        $items = $this->collectItems($identifier, $language, $maxDepth, $startLevel, $rootUid, $actor);
        $tree = $this->buildTree($items);
        $tree = $this->markActive($tree, $activeUrl, $activeRoute);
        $slice = $this->slice($tree, $maxDepth, $startLevel, $rootUid);
        $arrayLevel = null === $rootUid ? $startLevel : 1;

        return array_map(
            static fn (NavigationItem $item): array => $item->toArray($arrayLevel),
            $slice,
        );
    }

    /**
     * @return list<NavigationItem>
     */
    public function collectItems(
        string $identifier = 'main',
        string $language = 'en',
        int $maxDepth = 3,
        int $startLevel = 1,
        ?string $rootUid = null,
        ?AccessActor $actor = null,
    ): array {
        $maxDepth = max(1, $maxDepth);
        $startLevel = max(1, $startLevel);
        $items = $this->buildItems($identifier, $language);
        $event = new NavigationBuilderEvent($identifier, $language, $maxDepth, $startLevel, $rootUid, $items);
        $result = $this->eventDispatcher->dispatch($event, [
            'operation' => 'navigation_builder',
            'identifier' => $identifier,
            'language' => $language,
            'max_depth' => $maxDepth,
            'start_level' => $startLevel,
            'root_uid' => $rootUid,
        ]);

        $collectedItems = $result->isSuccess() ? $event->items() : $items;

        return $this->resolveUrls($this->filterByAccess($collectedItems, $actor));
    }

    /**
     * @return list<NavigationItem>
     */
    private function buildItems(string $identifier, string $language): array
    {
        try {
            $menu = $this->connection->fetchAssociative(
                'SELECT uid FROM site_menu WHERE identifier = :identifier AND active = 1',
                ['identifier' => $identifier],
            );

            if (false === $menu) {
                return [];
            }

            $rows = $this->connection->fetchAllAssociative(
                'SELECT uid, parent_uid, sort_order, labels, target_type, target_value, view_min_level, view_group_identifiers, metadata
                 FROM site_menu_item
                 WHERE menu_uid = :menu_uid
                 ORDER BY parent_uid ASC, sort_order ASC, uid ASC',
                [
                    'menu_uid' => $menu['uid'],
                ],
            );
        } catch (Throwable) {
            return [];
        }

        $items = [];

        foreach ($rows as $row) {
            $items[] = new NavigationItem(
                (string) $row['uid'],
                $this->label($this->decodeJson((string) $row['labels']), $language),
                (string) $row['target_type'],
                (string) $row['target_value'],
                null === $row['parent_uid'] ? null : (string) $row['parent_uid'],
                (int) $row['sort_order'],
                $this->metadata($row),
            );
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function metadata(array $row): array
    {
        $metadata = $this->decodeJson((string) $row['metadata']);
        $minLevel = null === $row['view_min_level'] ? null : (int) $row['view_min_level'];
        $accessGroups = null === $row['view_group_identifiers']
            ? []
            : $this->decodeJson((string) $row['view_group_identifiers']);

        if (null !== $minLevel && 0 < $minLevel) {
            $metadata['min_access_level'] = $minLevel;
        }

        if ([] !== $accessGroups) {
            $metadata['access_groups'] = array_values(array_filter(
                $accessGroups,
                static fn (mixed $group): bool => is_string($group),
            ));
        }

        return $metadata;
    }

    /**
     * @param list<NavigationItem> $items
     *
     * @return list<NavigationItem>
     */
    private function buildTree(array $items): array
    {
        $childrenByParent = [];

        foreach ($items as $item) {
            $childrenByParent[$item->parentUid() ?? ''][] = $item;
        }

        return $this->withChildren($childrenByParent[''] ?? [], $childrenByParent);
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

    /**
     * @param list<NavigationItem> $items
     *
     * @return list<NavigationItem>
     */
    private function filterByAccess(array $items, ?AccessActor $actor): array
    {
        if (null === $actor) {
            return $items;
        }

        return array_values(array_filter($items, static function (NavigationItem $item) use ($actor): bool {
            $minLevel = $item->metadata()['min_access_level'] ?? null;
            $accessGroups = $item->metadata()['access_groups'] ?? [];
            $anonymousOnly = $item->metadata()['anonymous_only'] ?? false;

            if (true === $anonymousOnly && null !== $actor->userUid()) {
                return false;
            }

            if (is_int($minLevel) && $actor->accessLevel() >= $minLevel) {
                return true;
            }

            if (is_array($accessGroups)) {
                foreach ($accessGroups as $group) {
                    if (is_string($group) && $actor->hasGroupIdentifier($group)) {
                        return true;
                    }
                }
            }

            return !is_int($minLevel) && [] === $accessGroups;
        }));
    }

    /**
     * @param list<NavigationItem> $items
     *
     * @return list<NavigationItem>
     */
    private function resolveUrls(array $items): array
    {
        return array_map(function (NavigationItem $item): NavigationItem {
            if ('route' !== $item->targetType()) {
                return $item->withResolvedUrl(match ($item->targetType()) {
                    'url', 'content' => $this->safeNavigationUrl($item->targetValue()),
                    default => '#',
                });
            }

            $parameters = $item->metadata()['route_parameters'] ?? [];

            if (!is_array($parameters)) {
                $parameters = [];
            }

            try {
                return $item->withResolvedUrl($this->urlGenerator->generate($item->targetValue(), $parameters));
            } catch (Throwable) {
                return $item->withResolvedUrl('#');
            }
        }, $items);
    }

    private function safeNavigationUrl(string $targetValue): string
    {
        $targetValue = trim($targetValue);

        if ('' === $targetValue || 1 === preg_match('/[\x00-\x1F\x7F]/', $targetValue)) {
            return '#';
        }

        if (str_starts_with($targetValue, '//') || str_starts_with($targetValue, '/\\')) {
            return '#';
        }

        $scheme = parse_url($targetValue, PHP_URL_SCHEME);

        if (null === $scheme) {
            return $targetValue;
        }

        if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            return '#';
        }

        $host = parse_url($targetValue, PHP_URL_HOST);

        return is_string($host) && '' !== trim($host) ? $targetValue : '#';
    }

    /**
     * @param list<NavigationItem> $items
     *
     * @return list<NavigationItem>
     */
    private function markActive(array $items, ?string $activeUrl, ?string $activeRoute): array
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
     * @param list<NavigationItem> $tree
     *
     * @return list<NavigationItem>
     */
    private function slice(array $tree, int $maxDepth, int $startLevel, ?string $rootUid): array
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

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $labels
     */
    private function label(array $labels, string $language): string
    {
        $label = $labels[$language] ?? $labels['en'] ?? reset($labels);

        return is_string($label) ? $label : '';
    }
}
