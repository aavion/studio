<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Content\ContentStatus;
use App\Content\ContentVisibility;
use App\Content\Read\ContentReadAccessPolicy;
use App\Content\Read\PublishedContentResolver;
use App\Core\Access\AccessActor;
use App\Core\Access\AccessLevel;
use App\Entity\AclGroup;
use App\Entity\ContentItem;
use App\Entity\Extension;
use App\Entity\UserAccount;
use App\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Throwable;

final readonly class ExtensionReferenceFacade
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?PublishedContentResolver $contentResolver = null,
        private ?ContentReadAccessPolicy $contentAccess = null,
        private ?Security $security = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    public function lookup(string $type, string $identifier, array $options = []): ?array
    {
        $type = $this->normalizeType($type);
        $identifier = trim($identifier);
        if ('' === $identifier) {
            return null;
        }

        return match ($type) {
            'content' => $this->lookupContent($identifier, $options),
            'content_route' => $this->lookupContentRoute($identifier, $options),
            'user' => $this->lookupUser($identifier),
            'acl_group' => $this->lookupAclGroup($identifier),
            'role' => $this->roleReference($identifier),
            'extension' => $this->lookupExtension($identifier),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    public function entity(string $uid, ?string $type = null, array $options = []): ?array
    {
        $uid = strtolower(trim($uid));
        if (!$this->isUuid($uid)) {
            return null;
        }

        $types = null === $type || '' === trim($type)
            ? ['content', 'user', 'acl_group', 'extension']
            : [$this->normalizeType($type)];

        $matches = [];
        foreach ($types as $candidateType) {
            $reference = match ($candidateType) {
                'content' => $this->contentReference($this->find(ContentItem::class, $uid), $options),
                'user' => $this->userReference($this->find(UserAccount::class, $uid)),
                'acl_group' => $this->aclGroupReference($this->find(AclGroup::class, $uid)),
                'extension' => $this->extensionReference($this->find(Extension::class, $uid)),
                default => null,
            };

            if (null !== $reference) {
                $matches[] = $reference;
            }
        }

        return 1 === count($matches) ? $matches[0] : null;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    private function lookupContent(string $identifier, array $options): ?array
    {
        if ($this->isUuid($identifier)) {
            return $this->contentReference($this->find(ContentItem::class, strtolower($identifier)), $options);
        }

        if (str_starts_with($identifier, '/')) {
            return $this->lookupContentRoute($identifier, $options);
        }

        return $this->contentReference($this->findOneBy(ContentItem::class, ['slug' => $identifier]), $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    private function lookupContentRoute(string $path, array $options): ?array
    {
        if (!$this->contentResolver instanceof PublishedContentResolver) {
            return null;
        }

        try {
            $view = $this->contentResolver->findByPath(
                $path,
                $this->actor(),
                is_string($options['language'] ?? null) ? (string) $options['language'] : '',
                is_string($options['variant'] ?? null) ? (string) $options['variant'] : 'default',
            );

            return null !== $view ? $this->contentReference($view->content(), $options) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function lookupUser(string $identifier): ?array
    {
        $user = $this->isUuid($identifier)
            ? $this->find(UserAccount::class, strtolower($identifier))
            : $this->findOneBy(UserAccount::class, ['username' => $identifier]);

        return $this->userReference($user);
    }

    private function lookupAclGroup(string $identifier): ?array
    {
        $group = $this->isUuid($identifier)
            ? $this->find(AclGroup::class, strtolower($identifier))
            : $this->findOneBy(AclGroup::class, ['identifier' => $identifier]);

        return $this->aclGroupReference($group);
    }

    private function lookupExtension(string $identifier): ?array
    {
        $extension = $this->isUuid($identifier)
            ? $this->find(Extension::class, strtolower($identifier))
            : $this->findOneBy(Extension::class, ['extensionName' => $identifier]);

        return $this->extensionReference($extension);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    private function contentReference(?object $content, array $options): ?array
    {
        if (!$content instanceof ContentItem || !$this->canViewContent($content)) {
            return null;
        }

        return [
            'type' => 'content',
            'uid' => $content->uid(),
            'slug' => $content->slug(),
            'parent_uid' => $content->parentUid(),
            'custom_url' => $content->customUrl(),
            'status' => $content->status()->value,
            'visibility' => $content->visibility()->value,
            'available_languages' => $content->availableLanguages(),
            'available_variants' => $content->availableVariants(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function userReference(?object $user): ?array
    {
        if (!$user instanceof UserAccount || !$this->canViewUser($user)) {
            return null;
        }

        return [
            'type' => 'user',
            'uid' => $user->uid(),
            'username' => $user->username(),
            'status' => $user->status()->value,
            'role' => $user->role()->value,
            'access_level' => $user->accessLevel(),
            'groups' => array_values(array_map(
                static fn (AclGroup $group): array => [
                    'uid' => $group->uid(),
                    'identifier' => $group->identifier(),
                    'name' => $group->name(),
                    'min_role' => $group->minRole(),
                ],
                array_filter($user->groups()->toArray(), static fn (mixed $group): bool => $group instanceof AclGroup),
            )),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function aclGroupReference(?object $group): ?array
    {
        if (!$group instanceof AclGroup || !$this->canViewAclGroup($group)) {
            return null;
        }

        return [
            'type' => 'acl_group',
            'uid' => $group->uid(),
            'identifier' => $group->identifier(),
            'name' => $group->name(),
            'min_role' => $group->minRole(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function roleReference(string $roleValue): ?array
    {
        $role = UserRole::tryFrom($roleValue);
        if (!$role instanceof UserRole) {
            return null;
        }

        return [
            'type' => 'role',
            'value' => $role->value,
            'access_level' => $role->accessLevel(),
            'symfony_roles' => $role->symfonyRoles(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extensionReference(?object $extension): ?array
    {
        if (!$extension instanceof Extension || !$this->canViewExtension($extension)) {
            return null;
        }

        return [
            'type' => 'extension',
            'uid' => $extension->uid(),
            'extension_name' => $extension->extensionName(),
            'status' => $extension->status()->value,
            'scopes' => $extension->scopeValues(),
            'manifest_version' => $extension->manifestVersion(),
            'installed_version' => $extension->installedVersion(),
            'available_version' => $extension->availableVersion(),
        ];
    }

    private function canViewContent(ContentItem $content): bool
    {
        if (ContentStatus::Published !== $content->status() || ContentVisibility::Public !== $content->visibility()) {
            return false;
        }

        if (!$this->contentAccess instanceof ContentReadAccessPolicy) {
            return true;
        }

        return $this->contentAccess->allowsView($content, $this->actor());
    }

    private function canViewUser(UserAccount $user): bool
    {
        $actor = $this->actor();

        return $actor->accessLevel() >= AccessLevel::ADMIN || $actor->userUid() === $user->uid();
    }

    private function canViewAclGroup(AclGroup $group): bool
    {
        $actor = $this->actor();

        return $actor->accessLevel() >= AccessLevel::ADMIN || $actor->hasGroupIdentifier($group->identifier());
    }

    private function canViewExtension(Extension $extension): bool
    {
        return ExtensionStatus::Active === $extension->status() || $this->actor()->accessLevel() >= AccessLevel::ADMIN;
    }

    private function actor(): AccessActor
    {
        $user = $this->security?->getUser();

        return $user instanceof UserAccount ? AccessActor::fromUserAccount($user) : AccessActor::anonymous();
    }

    /**
     * @param class-string $class
     */
    private function find(string $class, string $uid): ?object
    {
        try {
            return $this->entityManager->find($class, $uid);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param class-string $class
     * @param array<string, mixed> $criteria
     */
    private function findOneBy(string $class, array $criteria): ?object
    {
        try {
            return $this->entityManager->getRepository($class)->findOneBy($criteria);
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeType(string $type): string
    {
        return strtolower(str_replace('-', '_', trim($type)));
    }

    private function isUuid(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $value);
    }
}
