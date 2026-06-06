<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AclGroup;
use App\Entity\ContentItem;

final readonly class AclGroupContentReferenceProvider implements AclGroupReferenceProviderInterface
{
    public function __construct(
        private AclGroupReferenceQuery $references,
        private AclGroupReferenceValues $values,
    ) {
    }

    public function key(): string
    {
        return 'content_items';
    }

    public function impact(AclGroup $group): array
    {
        $rows = [];
        $identifier = $group->identifier();

        foreach ($this->contentItems($identifier) as $content) {
            $fields = [
                ...$this->values->fieldIfContains('acl_restrictions', $content->aclRestrictions(), $identifier),
                ...$this->values->fieldIfContains('view_group_identifiers', $content->viewGroupIdentifiers(), $identifier),
                ...$this->values->fieldIfContains('edit_group_identifiers', $content->editGroupIdentifiers(), $identifier),
                ...$this->values->fieldIfContains('manage_group_identifiers', $content->manageGroupIdentifiers(), $identifier),
            ];

            if ([] === $fields) {
                continue;
            }

            $rows[] = [
                'uid' => $content->uid(),
                'label' => $content->slug(),
                'fields' => $fields,
                'opens_published_access' => $this->opensPublishedAccess($content, $identifier),
            ];
        }

        return $rows;
    }

    public function removeReferences(AclGroup $group): void
    {
        $identifier = $group->identifier();

        foreach ($this->contentItems($identifier) as $content) {
            $content->setAclRestrictions($this->values->withoutIdentifier($content->aclRestrictions(), $identifier));
            $content->setViewRule($content->viewMinLevel(), $this->values->withoutIdentifierOrNull($content->viewGroupIdentifiers(), $identifier));
            $content->setEditRule($content->editMinLevel(), $this->values->withoutIdentifierOrNull($content->editGroupIdentifiers(), $identifier));
            $content->setManageRule($content->manageMinLevel(), $this->values->withoutIdentifierOrNull($content->manageGroupIdentifiers(), $identifier));
        }
    }

    public function removeBelowMinRoleReferences(AclGroup $group, int $minRole): int
    {
        return 0;
    }

    /**
     * @return list<ContentItem>
     */
    private function contentItems(string $identifier): array
    {
        return $this->references->entitiesByJsonColumns(
            ContentItem::class,
            'content_item',
            ['acl_restrictions', 'view_group_identifiers', 'edit_group_identifiers', 'manage_group_identifiers'],
            $identifier,
        );
    }

    private function opensPublishedAccess(ContentItem $content, string $identifier): bool
    {
        if (!$content->status()->isPubliclyRenderable()) {
            return false;
        }

        if ($content->aclRestrictions() === [$identifier]) {
            return true;
        }

        return [] === $content->aclRestrictions()
            && null === $content->viewMinLevel()
            && $content->viewGroupIdentifiers() === [$identifier];
    }
}
