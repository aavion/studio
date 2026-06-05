<?php

declare(strict_types=1);

namespace App\Navigation;

use Doctrine\DBAL\Connection;
use Throwable;

final readonly class NavigationItemRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<NavigationItem>
     */
    public function items(string $identifier, string $language): array
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
        $label = $labels[$language] ?? reset($labels);

        return is_string($label) ? $label : '';
    }
}
