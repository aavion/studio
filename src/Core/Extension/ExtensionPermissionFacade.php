<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Access\AccessActor;
use App\Core\Access\AccessCapability;
use App\Core\Access\AccessLevel;
use App\Core\Access\AccessRule;
use App\Entity\AclGroup;
use App\Entity\ContentItem;
use App\Entity\UserAccount;
use App\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Throwable;

final readonly class ExtensionPermissionFacade
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?Security $security = null,
    ) {
    }

    /**
     * @param array<string, mixed> $subject
     * @param array<string, mixed> $options
     */
    public function can(string $extensionName, string $action, array $subject = [], array $options = []): bool
    {
        if (!ExtensionManifestSpec::isValidSlug($extensionName)) {
            return false;
        }

        try {
            $action = $this->normalizeAction($action);

            return match ($action) {
                'role', 'min_role', 'access_level' => $this->canRole($subject),
                'acl_group', 'group' => $this->canAclGroup($subject),
                'content.view' => $this->canContent($subject, AccessCapability::View),
                'content.edit' => $this->canContent($subject, AccessCapability::Edit),
                'content.manage' => $this->canContent($subject, AccessCapability::Manage),
                default => false,
            };
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $subject
     */
    private function canRole(array $subject): bool
    {
        $minLevel = $this->minLevel($subject['min_level'] ?? $subject['access_level'] ?? null);
        if (null === $minLevel) {
            $role = UserRole::tryFrom(strtolower((string) ($subject['role'] ?? $subject['min_role'] ?? '')));
            $minLevel = $role?->accessLevel();
        }

        return null !== $minLevel && $this->actor()->accessLevel() >= $minLevel;
    }

    /**
     * @param array<string, mixed> $subject
     */
    private function canAclGroup(array $subject): bool
    {
        $identifier = $this->groupIdentifier($subject);

        return null !== $identifier && $this->actor()->hasGroupIdentifier($identifier);
    }

    /**
     * @param array<string, mixed> $subject
     */
    private function canContent(array $subject, AccessCapability $capability): bool
    {
        $content = $this->content($subject);
        if (!$content instanceof ContentItem) {
            return false;
        }

        $rule = match ($capability) {
            AccessCapability::View => AccessRule::from($content->viewMinLevel(), $content->viewGroupIdentifiers()),
            AccessCapability::Edit => AccessRule::from($content->editMinLevel(), $content->editGroupIdentifiers()),
            AccessCapability::Manage => AccessRule::from($content->manageMinLevel(), $content->manageGroupIdentifiers()),
            default => AccessRule::defaultFor($capability),
        };

        return $rule->isInherited()
            ? AccessRule::defaultFor($capability)->allows($this->actor())
            : $rule->allows($this->actor());
    }

    /**
     * @param array<string, mixed> $subject
     */
    private function content(array $subject): ?ContentItem
    {
        $uid = is_string($subject['uid'] ?? null) ? strtolower(trim((string) $subject['uid'])) : '';
        if ('' !== $uid && $this->isUuid($uid)) {
            $content = $this->entityManager->find(ContentItem::class, $uid);

            return $content instanceof ContentItem ? $content : null;
        }

        $slug = is_string($subject['slug'] ?? null) ? trim((string) $subject['slug']) : '';
        if ('' === $slug) {
            return null;
        }

        $content = $this->entityManager->getRepository(ContentItem::class)->findOneBy(['slug' => $slug]);

        return $content instanceof ContentItem ? $content : null;
    }

    /**
     * @param array<string, mixed> $subject
     */
    private function groupIdentifier(array $subject): ?string
    {
        $identifier = is_string($subject['identifier'] ?? null) ? trim((string) $subject['identifier']) : '';
        if ('' !== $identifier) {
            return $identifier;
        }

        $uid = is_string($subject['uid'] ?? null) ? strtolower(trim((string) $subject['uid'])) : '';
        if ('' === $uid || !$this->isUuid($uid)) {
            return null;
        }

        $group = $this->entityManager->find(AclGroup::class, $uid);

        return $group instanceof AclGroup ? $group->identifier() : null;
    }

    private function minLevel(mixed $value): ?int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return null;
        }

        $level = (int) $value;

        return $level >= AccessLevel::PUBLIC && $level <= AccessLevel::OWNER ? $level : null;
    }

    private function actor(): AccessActor
    {
        $user = $this->security?->getUser();

        return $user instanceof UserAccount ? AccessActor::fromUserAccount($user) : AccessActor::anonymous();
    }

    private function normalizeAction(string $action): string
    {
        return strtolower(str_replace(['-', ':'], ['_', '.'], trim($action)));
    }

    private function isUuid(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $value);
    }
}
