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
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserFlowConfig $userFlowConfig,
    ) {
    }

    /**
     * @param list<string> $newGroupIdentifiers
     */
    public function validateUserUpdate(AccessActor $actor, UserAccount $target, UserAccountStatus $newStatus, UserRole $newRole, array $newGroupIdentifiers): ?string
    {
        if ($this->isRestrictedAccessTarget($actor, $target)) {
            return 'admin.users.form.errors.target_higher_access';
        }

        if ($this->isRestrictedAccessLevel($actor, $newRole->accessLevel())) {
            return 'admin.users.form.errors.role_too_high';
        }

        if ($error = $this->validateUserGroups($actor, $newStatus, $newRole, $newGroupIdentifiers)) {
            return $error;
        }

        if ($error = $this->validateUserRoleFloor($newStatus, $newRole)) {
            return $error;
        }

        if ($this->isActor($actor, $target) && (!$newStatus->isUsable() || $newRole->accessLevel() < BackendArea::Admin->minimumAccessLevel())) {
            return 'admin.users.form.errors.self_lockout';
        }

        if (!$this->hasActiveOwnerAfterUserUpdate($target, $newStatus, $newRole)) {
            return 'admin.users.form.errors.last_owner';
        }

        return null;
    }

    public function validateUserAction(AccessActor $actor, UserAccount $target): ?string
    {
        return $this->isRestrictedAccessTarget($actor, $target)
            ? 'admin.users.form.errors.target_higher_access'
            : null;
    }

    public function allowsAccountClosure(UserAccount $target): bool
    {
        return $this->hasActiveOwnerAfterUserUpdate($target, UserAccountStatus::Deleted, UserRole::Public);
    }

    public function allowsSecurityReviewLock(UserAccount $target): bool
    {
        return $this->hasActiveOwnerAfterUserUpdate($target, UserAccountStatus::Inactive, $target->role());
    }

    /**
     * @param list<string> $groupIdentifiers
     */
    public function validateGroupAssignment(AccessActor $actor, array $groupIdentifiers, UserRole $targetRole = UserRole::User): ?string
    {
        return $this->validateUserGroups($actor, UserAccountStatus::Active, $targetRole, $groupIdentifiers);
    }

    public function validateRoleAssignment(AccessActor $actor, UserRole $role): ?string
    {
        return $this->isRestrictedAccessLevel($actor, $role->accessLevel())
            ? 'admin.users.form.errors.role_too_high'
            : null;
    }

    public function canAssignGroup(AccessActor $actor, AclGroup $group, ?UserRole $targetRole = null): bool
    {
        if ($this->isRestrictedAccessLevel($actor, $group->minRole())) {
            return false;
        }

        return null === $targetRole || $targetRole->accessLevel() >= $group->minRole();
    }

    public function validateGroupCreate(AccessActor $actor, int $accessLevel): ?string
    {
        return $this->isRestrictedAccessLevel($actor, $accessLevel)
            ? 'admin.groups.form.higher_access'
            : null;
    }

    public function validateGroupUpdate(AccessActor $actor, AclGroup $group, int $newAccessLevel): ?string
    {
        if ($this->isRestrictedAccessLevel($actor, $group->minRole()) || $this->isRestrictedAccessLevel($actor, $newAccessLevel)) {
            return 'admin.groups.form.higher_access';
        }

        return $this->validateGroupUpdateSystem($group, $newAccessLevel);
    }

    public function validateGroupUpdateSystem(AclGroup $group, int $newAccessLevel): ?string
    {
        try {
            AccessLevel::assert($newAccessLevel);
        } catch (\Throwable) {
            return 'admin.groups.form.invalid';
        }

        if ($this->isDefaultRegistrationGroup($group) && $newAccessLevel > AccessLevel::USER) {
            return 'admin.groups.form.default_acl_group_role_blocked';
        }

        return null;
    }

    public function validateGroupDelete(AccessActor $actor, AclGroup $group): ?string
    {
        if ($this->isRestrictedAccessLevel($actor, $group->minRole())) {
            return 'admin.groups.form.higher_access';
        }

        return $this->validateGroupDeleteSystem($group);
    }

    public function validateGroupDeleteSystem(AclGroup $group): ?string
    {
        if ($this->isDefaultRegistrationGroup($group)) {
            return 'admin.groups.form.default_acl_group_delete_blocked';
        }

        return null;
    }

    private function isRestrictedAccessTarget(AccessActor $actor, UserAccount $target): bool
    {
        return $this->isRestrictedAccessLevel($actor, $target->accessLevel());
    }

    private function isRestrictedAccessLevel(AccessActor $actor, int $accessLevel): bool
    {
        if ($actor->accessLevel() >= AccessLevel::OWNER) {
            return false;
        }

        return $accessLevel >= $actor->accessLevel();
    }

    private function isActor(AccessActor $actor, UserAccount $target): bool
    {
        return $actor->userUid() === $target->uid();
    }

    private function isDefaultRegistrationGroup(AclGroup $group): bool
    {
        return $group->identifier() === $this->userFlowConfig->defaultAclGroupIdentifier();
    }

    /**
     * @param list<string> $groupIdentifiers
     */
    private function validateUserRoleFloor(UserAccountStatus $status, UserRole $role): ?string
    {
        if (UserAccountStatus::Deleted === $status) {
            return null;
        }

        return $role->accessLevel() >= AccessLevel::USER
            ? null
            : 'admin.users.form.errors.role_too_low';
    }

    /**
     * @param list<string> $groupIdentifiers
     */
    private function validateUserGroups(AccessActor $actor, UserAccountStatus $status, UserRole $role, array $groupIdentifiers): ?string
    {
        if (UserAccountStatus::Deleted === $status) {
            return null;
        }

        if (!$this->allGroupsExist($groupIdentifiers)) {
            return 'admin.users.form.errors.group_invalid';
        }

        foreach ($this->groupsForIdentifiers($groupIdentifiers) as $group) {
            if ($this->isRestrictedAccessLevel($actor, $group->minRole())) {
                return 'admin.users.form.errors.group_min_role_too_high';
            }

            if ($role->accessLevel() < $group->minRole()) {
                return 'admin.users.form.errors.group_role_too_low';
            }
        }

        return null;
    }

    /**
     * @param list<string> $groupIdentifiers
     */
    private function allGroupsExist(array $groupIdentifiers): bool
    {
        $unique = array_values(array_unique($groupIdentifiers));

        if ([] === $unique) {
            return true;
        }

        return count($unique) === count($this->entityManager->getRepository(AclGroup::class)->findBy(['identifier' => $unique]));
    }

    /**
     * @param list<string> $groupIdentifiers
     */
    private function groupsForIdentifiers(array $groupIdentifiers): array
    {
        if ([] === $groupIdentifiers) {
            return [];
        }

        return array_values(array_filter(
            $this->entityManager->getRepository(AclGroup::class)->findBy(['identifier' => array_values(array_unique($groupIdentifiers))]),
            static fn (mixed $group): bool => $group instanceof AclGroup,
        ));
    }

    private function hasActiveOwnerAfterUserUpdate(UserAccount $target, UserAccountStatus $newStatus, UserRole $newRole): bool
    {
        foreach ($this->activeUsers() as $user) {
            if ($user->uid() === $target->uid()) {
                if ($newStatus->isUsable() && UserRole::Owner === $newRole) {
                    return true;
                }

                continue;
            }

            if (UserRole::Owner === $user->role()) {
                return true;
            }
        }

        return false;
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
