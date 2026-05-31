<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AccountToken;
use App\Entity\AclGroup;
use App\Entity\ContentItem;
use App\Entity\ContentSchemaVersion;
use App\Entity\SiteMenuItem;
use App\Entity\UserAccount;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AclGroupImpactService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function impact(AclGroup $group): array
    {
        $identifier = $group->identifier();
        $users = $this->affectedUsers($group);
        $tokens = $this->affectedAccountTokens($identifier);
        $contentItems = $this->affectedContentItems($identifier);
        $schemaVersions = $this->affectedSchemaVersions($identifier);
        $siteMenuItems = $this->affectedSiteMenuItems($identifier);

        return [
            'group_identifier' => $identifier,
            'users' => $users,
            'account_tokens' => $tokens,
            'content_items' => $contentItems,
            'content_schema_versions' => $schemaVersions,
            'site_menu_items' => $siteMenuItems,
            'summary' => [
                'users' => count($users),
                'account_tokens' => count($tokens),
                'content_items' => count($contentItems),
                'content_schema_versions' => count($schemaVersions),
                'site_menu_items' => count($siteMenuItems),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function removeReferences(AclGroup $group): array
    {
        $impact = $this->impact($group);
        $identifier = $group->identifier();

        foreach ($this->entityManager->getRepository(AccountToken::class)->findAll() as $token) {
            if ($token instanceof AccountToken && in_array($identifier, $token->groupIdentifiers(), true)) {
                $token->updateGroups($this->withoutIdentifier($token->groupIdentifiers(), $identifier));
            }
        }

        foreach ($this->entityManager->getRepository(ContentItem::class)->findAll() as $content) {
            if (!$content instanceof ContentItem) {
                continue;
            }

            $content->setAclRestrictions($this->withoutIdentifier($content->aclRestrictions(), $identifier));
            $content->setViewRule($content->viewMinLevel(), $this->withoutIdentifierOrNull($content->viewGroupIdentifiers(), $identifier));
            $content->setEditRule($content->editMinLevel(), $this->withoutIdentifierOrNull($content->editGroupIdentifiers(), $identifier));
            $content->setManageRule($content->manageMinLevel(), $this->withoutIdentifierOrNull($content->manageGroupIdentifiers(), $identifier));
        }

        foreach ($this->entityManager->getRepository(ContentSchemaVersion::class)->findAll() as $version) {
            if (!$version instanceof ContentSchemaVersion) {
                continue;
            }

            $version->setUseRule($version->useMinLevel(), $this->withoutIdentifierOrNull($version->useGroupIdentifiers(), $identifier));
            $version->setEditRule($version->editMinLevel(), $this->withoutIdentifierOrNull($version->editGroupIdentifiers(), $identifier));
            $version->setManageRule($version->manageMinLevel(), $this->withoutIdentifierOrNull($version->manageGroupIdentifiers(), $identifier));
        }

        foreach ($this->entityManager->getRepository(SiteMenuItem::class)->findAll() as $item) {
            if ($item instanceof SiteMenuItem) {
                $item->setViewRule($item->viewMinLevel(), $this->withoutIdentifierOrNull($item->viewGroupIdentifiers(), $identifier));
            }
        }

        return $impact;
    }

    /**
     * @return array{users: int, account_tokens: int}
     */
    public function removeBelowMinRoleReferences(AclGroup $group, int $minRole): array
    {
        $summary = ['users' => 0, 'account_tokens' => 0];

        foreach ($this->entityManager->getRepository(UserAccount::class)->findAll() as $user) {
            if (!$user instanceof UserAccount || !$user->groups()->contains($group) || $user->accessLevel() >= $minRole) {
                continue;
            }

            $user->removeGroup($group);
            ++$summary['users'];
        }

        $identifier = $group->identifier();
        foreach ($this->entityManager->getRepository(AccountToken::class)->findAll() as $token) {
            if (!$token instanceof AccountToken || !in_array($identifier, $token->groupIdentifiers(), true) || $token->role()->accessLevel() >= $minRole) {
                continue;
            }

            $token->updateGroups($this->withoutIdentifier($token->groupIdentifiers(), $identifier));
            ++$summary['account_tokens'];
        }

        return $summary;
    }

    /**
     * @return list<array{uid: string, username: string, email: string, status: string, role: string}>
     */
    private function affectedUsers(AclGroup $group): array
    {
        $rows = [];

        foreach ($this->entityManager->getRepository(UserAccount::class)->findBy([], ['username' => 'ASC']) as $user) {
            if (!$user instanceof UserAccount || !$user->groups()->contains($group)) {
                continue;
            }

            $rows[] = [
                'uid' => $user->uid(),
                'username' => $user->username(),
                'email' => $user->email(),
                'status' => $user->status()->value,
                'role' => $user->role()->value,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{uid: string, email: string, type: string, status: string, fields: list<string>}>
     */
    private function affectedAccountTokens(string $identifier): array
    {
        $rows = [];

        foreach ($this->entityManager->getRepository(AccountToken::class)->findAll() as $token) {
            if (!$token instanceof AccountToken || !in_array($identifier, $token->groupIdentifiers(), true)) {
                continue;
            }

            $rows[] = [
                'uid' => $token->uid(),
                'email' => $token->email(),
                'type' => $token->type()->value,
                'status' => $token->status()->value,
                'fields' => ['groups'],
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{uid: string, label: string, fields: list<string>}>
     */
    private function affectedContentItems(string $identifier): array
    {
        $rows = [];

        foreach ($this->entityManager->getRepository(ContentItem::class)->findAll() as $content) {
            if (!$content instanceof ContentItem) {
                continue;
            }

            $fields = [
                ...$this->fieldIfContains('acl_restrictions', $content->aclRestrictions(), $identifier),
                ...$this->fieldIfContains('view_group_identifiers', $content->viewGroupIdentifiers(), $identifier),
                ...$this->fieldIfContains('edit_group_identifiers', $content->editGroupIdentifiers(), $identifier),
                ...$this->fieldIfContains('manage_group_identifiers', $content->manageGroupIdentifiers(), $identifier),
            ];

            if ([] !== $fields) {
                $rows[] = [
                    'uid' => $content->uid(),
                    'label' => $content->slug(),
                    'fields' => $fields,
                    'opens_published_access' => $this->opensPublishedAccess($content, $identifier),
                ];
            }
        }

        return $rows;
    }

    private function opensPublishedAccess(ContentItem $content, string $identifier): bool
    {
        return $content->status()->isPubliclyRenderable()
            && $content->aclRestrictions() === [$identifier];
    }

    /**
     * @return list<array{uid: string, label: string, fields: list<string>}>
     */
    private function affectedSchemaVersions(string $identifier): array
    {
        $rows = [];

        foreach ($this->entityManager->getRepository(ContentSchemaVersion::class)->findAll() as $version) {
            if (!$version instanceof ContentSchemaVersion) {
                continue;
            }

            $fields = [
                ...$this->fieldIfContains('use_group_identifiers', $version->useGroupIdentifiers(), $identifier),
                ...$this->fieldIfContains('edit_group_identifiers', $version->editGroupIdentifiers(), $identifier),
                ...$this->fieldIfContains('manage_group_identifiers', $version->manageGroupIdentifiers(), $identifier),
            ];

            if ([] !== $fields) {
                $rows[] = [
                    'uid' => $version->uid(),
                    'label' => $version->schema()->identifier().' v'.$version->version(),
                    'fields' => $fields,
                ];
            }
        }

        return $rows;
    }

    /**
     * @return list<array{uid: string, label: string, fields: list<string>}>
     */
    private function affectedSiteMenuItems(string $identifier): array
    {
        $rows = [];

        foreach ($this->entityManager->getRepository(SiteMenuItem::class)->findAll() as $item) {
            if (!$item instanceof SiteMenuItem) {
                continue;
            }

            $fields = $this->fieldIfContains('view_group_identifiers', $item->viewGroupIdentifiers(), $identifier);

            if ([] !== $fields) {
                $rows[] = ['uid' => $item->uid(), 'label' => $item->uid(), 'fields' => $fields];
            }
        }

        return $rows;
    }

    /**
     * @param list<string>|null $values
     *
     * @return list<string>
     */
    private function fieldIfContains(string $field, ?array $values, string $identifier): array
    {
        return in_array($identifier, $values ?? [], true) ? [$field] : [];
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function withoutIdentifier(array $values, string $identifier): array
    {
        return array_values(array_filter($values, static fn (string $value): bool => $value !== $identifier));
    }

    /**
     * @param list<string>|null $values
     *
     * @return list<string>|null
     */
    private function withoutIdentifierOrNull(?array $values, string $identifier): ?array
    {
        if (null === $values) {
            return null;
        }

        return $this->withoutIdentifier($values, $identifier);
    }
}
