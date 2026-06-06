<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Access\AccessActor;
use App\Core\Routing\AbsoluteUriGenerator;
use App\Entity\AccountToken;
use App\Entity\AclGroup;
use App\Entity\UserAccount;
use App\Mail\AccountMailFlow;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AdminAccountTokenPolicy
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AbsoluteUriGenerator $absoluteUris,
        private UserFlowConfig $userFlowConfig,
        private AdminUserAccessPolicy $adminUserPolicy,
    ) {
    }

    public function validateRevocation(AccessActor $actor, AccountToken $token): ?string
    {
        return match ($token->type()) {
            AccountTokenType::Invitation, AccountTokenType::Registration => $this->validateRevocableTokenGroups($actor, $token),
            AccountTokenType::PasswordReset, AccountTokenType::SecurityReview => $token->user() instanceof UserAccount
                ? $this->adminUserPolicy->validateUserAction($actor, $token->user())
                : null,
        };
    }

    public function validateDelivery(AccessActor $actor, AccountToken $token): ?string
    {
        if ($token->user() instanceof UserAccount && ($error = $this->adminUserPolicy->validateUserAction($actor, $token->user()))) {
            return $error;
        }

        return match ($token->type()) {
            AccountTokenType::Invitation, AccountTokenType::Registration => $this->validateAccountLinkAssignment($actor, $token),
            AccountTokenType::PasswordReset, AccountTokenType::SecurityReview => $this->validateRecoveryTokenTarget($token),
        };
    }

    public function ttlForToken(AccountToken $token): string
    {
        return AccountTokenType::PasswordReset === $token->type()
            ? UserFlowConfig::PASSWORD_RESET_TTL
            : $this->userFlowConfig->accountLinkTtl();
    }

    public function flowForToken(AccountToken $token): AccountMailFlow
    {
        return match ($token->type()) {
            AccountTokenType::Invitation => AccountMailFlow::InvitationLink,
            AccountTokenType::Registration => AccountMailFlow::RegistrationLink,
            AccountTokenType::PasswordReset => AccountMailFlow::PasswordResetLink,
            AccountTokenType::SecurityReview => AccountMailFlow::PasswordChanged,
        };
    }

    public function urlForToken(AccountToken $token, string $plainToken): ?string
    {
        return match ($token->type()) {
            AccountTokenType::PasswordReset => $this->absoluteUris->generateUri(__METHOD__, 'user_password_reset_token', ['token' => $plainToken]),
            AccountTokenType::SecurityReview => $this->absoluteUris->generateUri(__METHOD__, 'user_security_review', ['token' => $plainToken]),
            default => $this->absoluteUris->generateUri(__METHOD__, 'user_invitation_accept', ['token' => $plainToken]),
        };
    }

    public function repairGroupsForReissue(AccountToken $token): void
    {
        if (!in_array($token->type(), [AccountTokenType::Invitation, AccountTokenType::Registration], true)) {
            return;
        }

        $validGroups = $this->validRegisteredGroupIdentifiers($token->groupIdentifiers());

        if ($validGroups !== $token->groupIdentifiers()) {
            $token->updateGroups($validGroups);
        }
    }

    private function validateRevocableTokenGroups(AccessActor $actor, AccountToken $token): ?string
    {
        $validGroups = $this->validRegisteredGroupIdentifiers($token->groupIdentifiers());

        if ([] === $validGroups) {
            return null;
        }

        return $this->adminUserPolicy->validateGroupAssignment($actor, $validGroups, $token->role());
    }

    private function validateAccountLinkAssignment(AccessActor $actor, AccountToken $token): ?string
    {
        if (UserRole::Public === $token->role()) {
            return 'admin.users.form.errors.role_too_low';
        }

        if ($token->user() instanceof UserAccount && UserAccountStatus::Deleted !== $token->user()->status()) {
            return 'admin.users.invitation.unavailable';
        }

        if ($error = $this->adminUserPolicy->validateRoleAssignment($actor, $token->role())) {
            return $error;
        }

        return $this->adminUserPolicy->validateGroupAssignment($actor, $token->groupIdentifiers(), $token->role());
    }

    private function validateRecoveryTokenTarget(AccountToken $token): ?string
    {
        $user = $token->user();

        return $user instanceof UserAccount && $user->status()->isUsable()
            ? null
            : 'admin.users.invitation.unavailable';
    }

    /**
     * @param list<string> $identifiers
     *
     * @return list<string>
     */
    private function validRegisteredGroupIdentifiers(array $identifiers): array
    {
        if ([] === $identifiers) {
            return [];
        }

        $groups = $this->entityManager->getRepository(AclGroup::class)->findBy(['identifier' => $identifiers]);
        $valid = [];

        foreach ($groups as $group) {
            if ($group instanceof AclGroup) {
                $valid[] = $group->identifier();
            }
        }

        sort($valid);

        return array_values(array_unique($valid));
    }
}
