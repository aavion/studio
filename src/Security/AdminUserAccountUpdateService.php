<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Access\AccessActor;
use App\Core\Message\MessageException;
use App\Core\State\StateMarkerKey;
use App\Core\State\StateMarkerRecorder;
use App\Core\State\StateSubjectType;
use App\Entity\UserAccount;
use App\Mail\AccountMailFlow;
use App\Mail\MailLocaleResolver;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AdminUserAccountUpdateService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserAccountLifecycle $userLifecycle,
        private AdminUserAccessPolicy $adminUserPolicy,
        private UserGroupMembershipManager $userGroups,
        private StateMarkerRecorder $stateMarkers,
        private AccountLinkDeliveryInterface $linkDelivery,
        private MailLocaleResolver $mailLocaleResolver,
    ) {
    }

    /**
     * @param list<string> $newGroupIdentifiers
     */
    public function update(AccessActor $actor, ?string $actorName, UserAccount $user, UserAccountStatus $status, UserRole $role, array $newGroupIdentifiers): AdminUserAccountUpdateResult
    {
        if ($error = $this->adminUserPolicy->validateUserUpdate($actor, $user, $status, $role, $newGroupIdentifiers)) {
            return AdminUserAccountUpdateResult::error($error, [
                'target_user' => $user->uid(),
                'error_key' => $error,
            ]);
        }

        $oldStatus = $user->status()->value;
        $oldRole = $user->role()->value;
        $oldGroups = $this->userGroups->identifiers($user);
        $oldAccessLevel = $user->accessLevel();
        $effects = $this->userLifecycle->changeStatus($user, $status, $actorName);
        $user->changeRole($role);
        $this->userGroups->replaceExisting($user, $newGroupIdentifiers);
        $this->stateMarkers->record(StateSubjectType::USER_ACCOUNT, $user->uid(), StateMarkerKey::MODIFIED, $actorName, 'admin_update', [
            'old_role' => $oldRole,
            'new_role' => $role->value,
            'old_groups' => $oldGroups,
            'new_groups' => $newGroupIdentifiers,
        ]);

        try {
            $this->entityManager->flush();
        } catch (MessageException $exception) {
            return AdminUserAccountUpdateResult::error($exception->messageKey(), [
                'target_user' => $user->uid(),
                'error_key' => $exception->messageKey(),
            ]);
        }

        return AdminUserAccountUpdateResult::success('admin.users.saved', [
            'target_user' => $user->uid(),
            'old_status' => $oldStatus,
            'new_status' => $status->value,
            'old_role' => $oldRole,
            'new_role' => $role->value,
            'old_groups' => $oldGroups,
            'new_groups' => $this->userGroups->identifiers($user),
            'old_access_level' => $oldAccessLevel,
            'new_access_level' => $user->accessLevel(),
            ...$effects,
        ]);
    }

    public function changeDeletedStatus(AccessActor $actor, ?string $actorName, UserAccount $user, UserAccountStatus $status): AdminUserAccountUpdateResult
    {
        $groups = $this->userGroups->identifiers($user);
        $role = UserAccountStatus::Active === $status && UserRole::Public === $user->role()
            ? UserRole::User
            : $user->role();
        $error = $this->adminUserPolicy->validateUserUpdate($actor, $user, $status, $role, $groups);

        if (null !== $error) {
            return AdminUserAccountUpdateResult::error($error, [
                'target_user' => $user->uid(),
                'error_key' => $error,
            ], 'user.deleted_account_update_failed');
        }

        $oldStatus = $user->status()->value;
        $oldRole = $user->role()->value;
        $oldGroups = $this->userGroups->identifiers($user);
        $effects = $this->userLifecycle->changeStatus($user, $status, $actorName);
        $user->changeRole($role);

        $this->entityManager->flush();

        if (UserAccountStatus::Active === $status) {
            $this->linkDelivery->notifyAddress($user->email(), AccountMailFlow::AccountRestored, $this->mailLocaleResolver->forAdminAction($user), [
                'username' => $user->username(),
                'user_uid' => $user->uid(),
            ]);
        }

        return AdminUserAccountUpdateResult::success(
            UserAccountStatus::Active === $status ? 'admin.users.deleted.activated' : 'admin.users.deleted.deactivated',
            [
                'target_user' => $user->uid(),
                'old_status' => $oldStatus,
                'new_status' => $status->value,
                'old_role' => $oldRole,
                'new_role' => $role->value,
                'old_groups' => $oldGroups,
                'new_groups' => $this->userGroups->identifiers($user),
                ...$effects,
            ],
            UserAccountStatus::Active === $status ? 'user.deleted_account_activated' : 'user.deleted_account_deactivated',
        );
    }
}
