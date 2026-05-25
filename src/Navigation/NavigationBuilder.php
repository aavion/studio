<?php

declare(strict_types=1);

namespace App\Navigation;

use App\Core\Event\PublicEventDispatcher;
use App\Navigation\Event\NavigationBuilderEvent;
use Doctrine\DBAL\Connection;
use Throwable;

final readonly class NavigationBuilder
{
    public function __construct(
        private Connection $connection,
        private PublicEventDispatcher $eventDispatcher,
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
    ): array {
        $maxDepth = max(1, $maxDepth);
        $startLevel = max(1, $startLevel);
        $items = $this->collectItems($identifier, $language, $maxDepth, $startLevel, $rootUid);
        $tree = $this->buildTree($items);
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

        return $result->isSuccess() ? $event->items() : $items;
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
                'SELECT uid, parent_uid, sort_order, labels, target_type, target_value, metadata
                 FROM site_menu_item
                 WHERE menu_uid = :menu_uid
                   AND (view_min_level IS NULL OR view_min_level <= 0)
                   AND (view_group_identifiers IS NULL OR view_group_identifiers = :empty_json)
                 ORDER BY parent_uid ASC, sort_order ASC, uid ASC',
                [
                    'menu_uid' => $menu['uid'],
                    'empty_json' => '[]',
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
                $this->decodeJson((string) $row['metadata']),
            );
        }

        return $items;
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
