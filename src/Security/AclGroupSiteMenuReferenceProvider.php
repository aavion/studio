<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AclGroup;
use App\Entity\SiteMenuItem;

final readonly class AclGroupSiteMenuReferenceProvider implements AclGroupReferenceProviderInterface
{
    public function __construct(
        private AclGroupReferenceQuery $references,
        private AclGroupReferenceValues $values,
    ) {
    }

    public function key(): string
    {
        return 'site_menu_items';
    }

    public function impact(AclGroup $group): array
    {
        $rows = [];
        $identifier = $group->identifier();

        foreach ($this->siteMenuItems($identifier) as $item) {
            $fields = $this->values->fieldIfContains('view_group_identifiers', $item->viewGroupIdentifiers(), $identifier);

            if ([] === $fields) {
                continue;
            }

            $rows[] = [
                'uid' => $item->uid(),
                'label' => $item->uid(),
                'fields' => $fields,
                'target_type' => $item->targetType(),
                'target_value' => $item->targetValue(),
                'opens_public_access' => null === $item->viewMinLevel()
                    && $item->viewGroupIdentifiers() === [$identifier],
            ];
        }

        return $rows;
    }

    public function removeReferences(AclGroup $group): void
    {
        $identifier = $group->identifier();

        foreach ($this->siteMenuItems($identifier) as $item) {
            $item->setViewRule($item->viewMinLevel(), $this->values->withoutIdentifierOrNull($item->viewGroupIdentifiers(), $identifier));
        }
    }

    public function removeBelowMinRoleReferences(AclGroup $group, int $minRole): int
    {
        return 0;
    }

    /**
     * @return list<SiteMenuItem>
     */
    private function siteMenuItems(string $identifier): array
    {
        return $this->references->entitiesByJsonColumns(
            SiteMenuItem::class,
            'site_menu_item',
            ['view_group_identifiers'],
            $identifier,
        );
    }
}
