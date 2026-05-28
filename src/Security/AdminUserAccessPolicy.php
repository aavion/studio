<?php

declare(strict_types=1);

namespace App\Security;

use App\Backend\BackendArea;
use App\Core\Access\AccessActor;
use App\Core\Access\AccessLevel;
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
        if ($this->isRestrictedAccessTarget($actor, $target)) {
            return 'admin.users.form.errors.target_higher_access';
        }

        $newAccessLevel = $this->accessLevelForGroupIdentifiers($newGroupIdentifiers);

        if ($this->isRestrictedAccessLevel($actor, $newAccessLevel)) {
            return 'admin.users.form.errors.group_level_too_high';
        }

        if ($error = $this->validateUserGroupFloor($newStatus, $newGroupIdentifiers, $newAccessLevel)) {
            return $error;
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
        return $this->isRestrictedAccessTarget($actor, $target)
            ? 'admin.users.form.errors.target_higher_access'
            : null;
    }

    /**
     * @param list<string> $groupIdentifiers
     */
    public function validateGroupAssignment(AccessActor $actor, array $groupIdentifiers): ?string
    {
        $accessLevel = $this->accessLevelForGroupIdentifiers($groupIdentifiers);

        if ($this->isRestrictedAccessLevel($actor, $accessLevel)) {
            return 'admin.users.form.errors.group_level_too_high';
        }

        return $this->validateUserGroupFloor(UserAccountStatus::Active, $groupIdentifiers, $accessLevel);
    }

    public function canAssignGroup(AccessActor $actor, AclGroup $group): bool
    {
        return !$this->isRestrictedAccessLevel($actor, $group->accessLevel());
    }

    public function validateGroupCreate(AccessActor $actor, int $accessLevel): ?string
    {
        return $this->isRestrictedAccessLevel($actor, $accessLevel)
            ? 'admin.groups.form.higher_access'
            : null;
    }

    public function validateGroupUpdate(AccessActor $actor, AclGroup $group, int $newAccessLevel): ?string
    {
        if ($this->isRestrictedAccessLevel($actor, $group->accessLevel()) || $this->isRestrictedAccessLevel($actor, $newAccessLevel)) {
            return 'admin.groups.form.higher_access';
        }

        if ($error = $this->validateGroupUpdateSystem($group, $newAccessLevel)) {
            return $error;
        }

        if ($this->actorWouldLoseAdminAreaAccess($actor, $group, $newAccessLevel)) {
            return 'admin.groups.form.self_lockout';
        }

        return null;
    }

    public function validateGroupUpdateSystem(AclGroup $group, int $newAccessLevel): ?string
    {
        try {
            AccessLevel::assert($newAccessLevel);
        } catch (\Throwable) {
            return 'admin.groups.form.invalid';
        }

        if (!$this->hasActiveAdminAfterGroupLevelChange($group, $newAccessLevel)) {
            return 'admin.groups.form.last_admin';
        }

        if (!$this->preservesRegisteredUserGroupFloorAfterGroupChange($group, $newAccessLevel)) {
            return 'admin.groups.form.registered_user_floor';
        }

        return null;
    }

    public function validateGroupDelete(AccessActor $actor, AclGroup $group): ?string
    {
        if ($this->isRestrictedAccessLevel($actor, $group->accessLevel())) {
            return 'admin.groups.form.higher_access';
        }

        if ($error = $this->validateGroupDeleteSystem($group)) {
            return $error;
        }

        if ($this->actorWouldLoseAdminAreaAccess($actor, $group, null)) {
            return 'admin.groups.form.self_lockout';
        }

        return null;
    }

    public function validateGroupDeleteSystem(AclGroup $group): ?string
    {
        if (!$this->hasActiveAdminAfterGroupDeletion($group)) {
            return 'admin.groups.form.last_admin';
        }

        if (!$this->preservesRegisteredUserGroupFloorAfterGroupChange($group, null)) {
            return 'admin.groups.form.registered_user_floor';
        }

        return null;
    }

    private function isRestrictedAccessTarget(AccessActor $actor, UserAccount $target): bool
    {
        return $this->isRestrictedAccessLevel($actor, $target->maxAccessLevel());
    }

    private function isRestrictedAccessLevel(AccessActor $actor, int $accessLevel): bool
    {
        if ($actor->accessLevel() >= AccessLevel::ADMIN) {
            return false;
        }

        return $accessLevel >= $actor->accessLevel();
    }

    private function isActor(AccessActor $actor, UserAccount $target): bool
    {
        return $actor->userUid() === $target->uid();
    }

    /**
     * @param list<string> $groupIdentifiers
     */
    private function validateUserGroupFloor(UserAccountStatus $status, array $groupIdentifiers, int $accessLevel): ?string
    {
        if (UserAccountStatus::Deleted === $status) {
            return null;
        }

        if ([] === $groupIdentifiers) {
            return 'admin.users.form.errors.group_required';
        }

        return $accessLevel >= AccessLevel::REGISTERED
            ? null
            : 'admin.users.form.errors.group_access_too_low';
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

    private function groupCountWithGroupChange(UserAccount $user, AclGroup $changedGroup, ?int $newAccessLevel): int
    {
        $count = 0;

        foreach ($user->groups() as $group) {
            if (!$group instanceof AclGroup) {
                continue;
            }

            if ($group->uid() === $changedGroup->uid() && null === $newAccessLevel) {
                continue;
            }

            ++$count;
        }

        return $count;
    }

    private function preservesRegisteredUserGroupFloorAfterGroupChange(AclGroup $changedGroup, ?int $newAccessLevel): bool
    {
        foreach ($this->registeredUsers() as $user) {
            if (
                $this->groupCountWithGroupChange($user, $changedGroup, $newAccessLevel) < 1
                || $this->maxAccessLevelWithGroupChange($user, $changedGroup, $newAccessLevel) < AccessLevel::REGISTERED
            ) {
                return false;
            }
        }

        return true;
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

    /**
     * @return list<UserAccount>
     */
    private function registeredUsers(): array
    {
        return array_values(array_filter(
            $this->entityManager->getRepository(UserAccount::class)->findAll(),
            static fn (mixed $user): bool => $user instanceof UserAccount && UserAccountStatus::Deleted !== $user->status(),
        ));
    }
}
