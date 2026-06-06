<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Access\AccessActor;
use App\Core\Log\AuditLoggerInterface;
use App\Core\Validation\EmailAddress;
use App\Entity\AccountToken;
use App\Entity\UserAccount;
use App\Mail\AccountMailFlow;
use App\Mail\MailLocaleResolver;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final class AdminUserInvitationWorkflow
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountTokenIssuer $tokenIssuer,
        private readonly AccountTokenMaintenance $tokenMaintenance,
        private readonly AccountLinkDeliveryInterface $linkDelivery,
        private readonly AdminAccountTokenPolicy $accountTokenPolicy,
        private readonly MailLocaleResolver $mailLocaleResolver,
        private readonly UserFlowConfig $userFlowConfig,
        private readonly AdminUserAccessPolicy $adminUserPolicy,
        private readonly AccountReactivationAccessResolver $reactivationAccess,
        private readonly UserGroupMembershipManager $userGroups,
        private readonly AuditLoggerInterface $auditLogger,
    ) {
    }

    /**
     * @param list<string> $groups
     */
    public function invite(AccessActor $actor, string $email, ?UserRole $role, array $groups): AdminAccountTokenActionResult
    {
        $email = EmailAddress::normalize($email);

        try {
            if (!EmailAddress::isValid($email)) {
                return AdminAccountTokenActionResult::error('admin.users.form.errors.email_invalid');
            }

            if (!$role instanceof UserRole || UserRole::Public === $role) {
                return AdminAccountTokenActionResult::error('admin.users.form.errors.invalid_role');
            }

            if ($error = $this->adminUserPolicy->validateRoleAssignment($actor, $role)) {
                return AdminAccountTokenActionResult::error($error);
            }

            $existingUser = $this->userByEmail($email);
            $targetRole = $existingUser instanceof UserAccount && $existingUser->role()->accessLevel() > $role->accessLevel()
                ? $existingUser->role()
                : $role;

            if ($existingUser instanceof UserAccount && UserAccountStatus::Deleted !== $existingUser->status()) {
                return $this->updateExistingUser($actor, $existingUser, $email, $role, $targetRole, $groups);
            }

            if ($existingUser instanceof UserAccount && ($error = $this->adminUserPolicy->validateUserAction($actor, $existingUser))) {
                return AdminAccountTokenActionResult::error($error);
            }

            $tokenRole = $existingUser instanceof UserAccount ? $this->reactivationAccess->role($existingUser) : $role;
            $tokenGroups = $existingUser instanceof UserAccount ? $this->reactivationAccess->groupIdentifiers($existingUser, $tokenRole) : $groups;

            if (!$existingUser instanceof UserAccount && ($error = $this->adminUserPolicy->validateGroupAssignment($actor, $tokenGroups, $tokenRole))) {
                return AdminAccountTokenActionResult::error($error);
            }

            $this->tokenMaintenance->revokePendingForEmail($email, [AccountTokenType::Invitation, AccountTokenType::Registration]);
            [$token, $plainToken] = $this->tokenIssuer->issue(
                AccountTokenType::Invitation,
                $email,
                $tokenGroups,
                UserAccountStatus::Deleted === $existingUser?->status() ? $existingUser : null,
                role: $tokenRole,
                ttl: $this->userFlowConfig->accountLinkTtl(),
            );
            $url = $this->accountTokenPolicy->urlForToken($token, $plainToken);

            if (null === $url) {
                return AdminAccountTokenActionResult::error('admin.users.form.errors.mail_delivery_failed');
            }

            $this->entityManager->persist($token);
            $this->entityManager->flush();
            $this->linkDelivery->deliver($token, AccountMailFlow::InvitationLink, $url, $this->mailLocaleResolver->forAdminAction());
            $this->audit($actor, 'user.invitation_created', ['email' => $email, 'role' => $tokenRole->value, 'groups' => $tokenGroups, 'token_uid' => $token->uid()]);

            return AdminAccountTokenActionResult::success('admin.users.invitation.created');
        } catch (Throwable) {
            return AdminAccountTokenActionResult::error('admin.users.form.errors.invalid_invitation');
        }
    }

    public function approve(AccessActor $actor, string $uid): AdminAccountTokenActionResult
    {
        $token = $this->entityManager->find(AccountToken::class, $uid);

        if (!$token instanceof AccountToken || AccountTokenType::Registration !== $token->type() || AccountTokenStatus::PendingApproval !== $token->status()) {
            return AdminAccountTokenActionResult::error('admin.users.invitation.unavailable');
        }

        $this->accountTokenPolicy->repairGroupsForReissue($token);

        if ($error = $this->accountTokenPolicy->validateDelivery($actor, $token)) {
            return AdminAccountTokenActionResult::error($error);
        }

        $plainToken = $this->tokenIssuer->reissue($token, $this->accountTokenPolicy->ttlForToken($token));
        $url = $this->accountTokenPolicy->urlForToken($token, $plainToken);

        if (null === $url) {
            return AdminAccountTokenActionResult::error('admin.users.form.errors.mail_delivery_failed');
        }

        $token->approve();
        $this->entityManager->flush();
        $this->linkDelivery->notify($token, AccountMailFlow::RegistrationApproved, locale: $this->mailLocaleResolver->forAdminAction($token->user()));
        $this->linkDelivery->deliver($token, AccountMailFlow::RegistrationLink, $url, $this->mailLocaleResolver->forAdminAction($token->user()));
        $this->audit($actor, 'user.registration_approved', ['email' => $token->email(), 'token_uid' => $token->uid()]);

        return AdminAccountTokenActionResult::success('admin.users.invitation.approved');
    }

    public function reissue(AccessActor $actor, string $uid): AdminAccountTokenActionResult
    {
        $token = $this->entityManager->find(AccountToken::class, $uid);

        if (!$token instanceof AccountToken || AccountTokenStatus::Pending !== $token->status()) {
            return AdminAccountTokenActionResult::error('admin.users.invitation.unavailable');
        }

        $this->accountTokenPolicy->repairGroupsForReissue($token);

        if ($error = $this->accountTokenPolicy->validateDelivery($actor, $token)) {
            return AdminAccountTokenActionResult::error($error);
        }

        $plainToken = $this->tokenIssuer->reissue($token, $this->accountTokenPolicy->ttlForToken($token));
        $url = $this->accountTokenPolicy->urlForToken($token, $plainToken);

        if (null === $url) {
            return AdminAccountTokenActionResult::error('admin.users.form.errors.mail_delivery_failed');
        }

        $this->entityManager->flush();
        $this->linkDelivery->deliver($token, $this->accountTokenPolicy->flowForToken($token), $url, $this->mailLocaleResolver->forAdminAction($token->user()));
        $this->audit($actor, 'user.account_token_reissued', ['email' => $token->email(), 'token_uid' => $token->uid(), 'token_type' => $token->type()->value]);

        return AdminAccountTokenActionResult::success('admin.users.invitation.reissued');
    }

    public function revoke(AccessActor $actor, string $uid): AdminAccountTokenActionResult
    {
        $token = $this->entityManager->find(AccountToken::class, $uid);

        if (!$token instanceof AccountToken || !in_array($token->status(), [AccountTokenStatus::Pending, AccountTokenStatus::PendingApproval], true)) {
            return AdminAccountTokenActionResult::error('admin.users.invitation.unavailable');
        }

        if ($error = $this->accountTokenPolicy->validateRevocation($actor, $token)) {
            return AdminAccountTokenActionResult::error($error);
        }

        $wasPendingApproval = AccountTokenStatus::PendingApproval === $token->status();
        $token->revoke();
        $this->entityManager->flush();

        if ($wasPendingApproval) {
            $this->linkDelivery->notify($token, AccountMailFlow::RegistrationRejected, locale: $this->mailLocaleResolver->forAdminAction($token->user()));
        }

        $this->audit($actor, 'user.account_token_revoked', ['email' => $token->email(), 'token_uid' => $token->uid()]);

        return AdminAccountTokenActionResult::success('admin.users.invitation.revoked');
    }

    /**
     * @param list<string> $groups
     */
    private function updateExistingUser(AccessActor $actor, UserAccount $existingUser, string $email, UserRole $role, UserRole $targetRole, array $groups): AdminAccountTokenActionResult
    {
        if ($actor->userUid() === $existingUser->uid()) {
            return AdminAccountTokenActionResult::error('admin.users.form.errors.self_invitation');
        }

        if ($error = $this->adminUserPolicy->validateUserAction($actor, $existingUser)) {
            return AdminAccountTokenActionResult::error($error);
        }

        if ($error = $this->adminUserPolicy->validateGroupAssignment($actor, $groups, $targetRole)) {
            return AdminAccountTokenActionResult::error($error);
        }

        $oldRole = $existingUser->role()->value;
        $oldGroups = $this->userGroups->identifiers($existingUser);
        $this->userGroups->addExisting($existingUser, $groups);
        $existingUser->changeRole($targetRole);
        $this->entityManager->flush();
        $this->audit($actor, 'user.invitation_existing_account_updated', [
            'target_user' => $existingUser->uid(),
            'email' => $email,
            'old_role' => $oldRole,
            'new_role' => $targetRole->value,
            'old_groups' => $oldGroups,
            'new_groups' => $this->userGroups->identifiers($existingUser),
            'requested_role' => $role->value,
            'requested_groups' => $groups,
        ]);

        return AdminAccountTokenActionResult::success('admin.users.invitation.existing_updated');
    }

    private function userByEmail(string $email): ?UserAccount
    {
        $user = $this->entityManager->getRepository(UserAccount::class)->findOneByEmail($email);

        return $user instanceof UserAccount ? $user : null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function audit(AccessActor $actor, string $action, array $context): void
    {
        try {
            $this->auditLogger->log($actor, $action, $context);
        } catch (Throwable) {
            return;
        }
    }
}
