<?php

declare(strict_types=1);

namespace App\Navigation;

use App\Core\Access\AccessActor;

final readonly class NavigationAccessFilter
{
    /**
     * @param list<NavigationItem> $items
     *
     * @return list<NavigationItem>
     */
    public function filter(array $items, ?AccessActor $actor): array
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
}
