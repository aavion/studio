<?php

declare(strict_types=1);

namespace App\Security;

use App\Backend\BackendArea;
use App\Core\Access\AccessActor;
use App\Entity\AclGroup;
use App\Entity\UserAccount;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AdminUserAccessPolicy
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param list<string> $newGroupIdentifiers
     */
    public function validateUserUpdate(AccessActor $actor, UserAccount $target, UserAccountStatus $newStatus, array $newGroupIdentifiers): ?string
    {
        if ($this->isHigherAccessTarget($actor, $target)) {
            return 'admin.users.form.errors.target_higher_access';
        }

        $newAccessLevel = $this->accessLevelForGroupIdentifiers($newGroupIdentifiers);

        if ($newAccessLevel > $actor->accessLevel()) {
            return 'admin.users.form.errors.group_level_too_high';
        }

        if ($this->isActor($actor, $target) && (!$newStatus->isUsable() || $newAccessLevel < BackendArea::Admin->minimumAccessLevel())) {
            return 'admin.users.form.errors.self_lockout';
        }

        if (!$this->hasActiveAdminAfterUserUpdate($target, $newStatus, $newAccessLevel)) {
            return 'admin.users.form.errors.last_admin';
        }

        return null;
    }

    public function validateUserAction(AccessActor $actor, UserAccount $target): ?string
    {
        return $this->isHigherAccessTarget($actor, $target)
            ? 'admin.users.form.errors.target_higher_access'
            : null;
    }

    /**
     * @param list<string> $groupIdentifiers
     */
    public function validateGroupAssignment(AccessActor $actor, array $groupIdentifiers): ?string
    {
        return $this->accessLevelForGroupIdentifiers($groupIdentifiers) > $actor->accessLevel()
            ? 'admin.users.form.errors.group_level_too_high'
            : null;
    }

    public function validateGroupCreate(AccessActor $actor, int $accessLevel): ?string
    {
        return $accessLevel > $actor->accessLevel()
            ? 'admin.groups.form.higher_access'
            : null;
    }

    public function validateGroupUpdate(AccessActor $actor, AclGroup $group, int $newAccessLevel): ?string
    {
        if ($group->accessLevel() > $actor->accessLevel() || $newAccessLevel > $actor->accessLevel()) {
            return 'admin.groups.form.higher_access';
        }

        if ($this->actorWouldLoseAdminAreaAccess($actor, $group, $newAccessLevel)) {
            return 'admin.groups.form.self_lockout';
        }

        if (!$this->hasActiveAdminAfterGroupLevelChange($group, $newAccessLevel)) {
            return 'admin.groups.form.last_admin';
        }

        return null;
    }

    public function validateGroupDelete(AccessActor $actor, AclGroup $group): ?string
    {
        if ($group->accessLevel() > $actor->accessLevel()) {
            return 'admin.groups.form.higher_access';
        }

        if ($this->actorWouldLoseAdminAreaAccess($actor, $group, null)) {
            return 'admin.groups.form.self_lockout';
        }

        if (!$this->hasActiveAdminAfterGroupDeletion($group)) {
            return 'admin.groups.form.last_admin';
        }

        return null;
    }

    private function isHigherAccessTarget(AccessActor $actor, UserAccount $target): bool
    {
        return !$this->isActor($actor, $target) && $target->maxAccessLevel() > $actor->accessLevel();
    }

    private function isActor(AccessActor $actor, UserAccount $target): bool
    {
        return $actor->userUid() === $target->uid();
    }

    /**
     * @param list<string> $groupIdentifiers
     */
    private function accessLevelForGroupIdentifiers(array $groupIdentifiers): int
    {
        if ([] === $groupIdentifiers) {
            return 0;
        }

        $max = 0;
        $groups = $this->entityManager->getRepository(AclGroup::class)->findBy(['identifier' => $groupIdentifiers]);

        foreach ($groups as $group) {
            if ($group instanceof AclGroup) {
                $max = max($max, $group->accessLevel());
            }
        }

        return $max;
    }

    private function hasActiveAdminAfterUserUpdate(UserAccount $target, UserAccountStatus $newStatus, int $newAccessLevel): bool
    {
        foreach ($this->activeUsers() as $user) {
            if ($user->uid() === $target->uid()) {
                if ($newStatus->isUsable() && $newAccessLevel >= BackendArea::Admin->minimumAccessLevel()) {
                    return true;
                }

                continue;
            }

            if ($user->maxAccessLevel() >= BackendArea::Admin->minimumAccessLevel()) {
                return true;
            }
        }

        return false;
    }

    private function hasActiveAdminAfterGroupLevelChange(AclGroup $changedGroup, int $newAccessLevel): bool
    {
        foreach ($this->activeUsers() as $user) {
            if ($this->maxAccessLevelWithGroupChange($user, $changedGroup, $newAccessLevel) >= BackendArea::Admin->minimumAccessLevel()) {
                return true;
            }
        }

        return false;
    }

    private function hasActiveAdminAfterGroupDeletion(AclGroup $deletedGroup): bool
    {
        foreach ($this->activeUsers() as $user) {
            if ($this->maxAccessLevelWithGroupChange($user, $deletedGroup, null) >= BackendArea::Admin->minimumAccessLevel()) {
                return true;
            }
        }

        return false;
    }

    private function actorWouldLoseAdminAreaAccess(AccessActor $actor, AclGroup $group, ?int $newAccessLevel): bool
    {
        $userUid = $actor->userUid();

        if (null === $userUid) {
            return true;
        }

        $user = $this->entityManager->find(UserAccount::class, $userUid);

        return $user instanceof UserAccount
            && $this->maxAccessLevelWithGroupChange($user, $group, $newAccessLevel) < BackendArea::Admin->minimumAccessLevel();
    }

    private function maxAccessLevelWithGroupChange(UserAccount $user, AclGroup $changedGroup, ?int $newAccessLevel): int
    {
        $max = 0;

        foreach ($user->groups() as $group) {
            if (!$group instanceof AclGroup) {
                continue;
            }

            if ($group->uid() === $changedGroup->uid()) {
                if (null !== $newAccessLevel) {
                    $max = max($max, $newAccessLevel);
                }

                continue;
            }

            $max = max($max, $group->accessLevel());
        }

        return $max;
    }

    /**
     * @return list<UserAccount>
     */
    private function activeUsers(): array
    {
        return array_values(array_filter(
            $this->entityManager->getRepository(UserAccount::class)->findBy(['status' => UserAccountStatus::Active]),
            static fn (mixed $user): bool => $user instanceof UserAccount,
        ));
    }
}
