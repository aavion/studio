<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Access\AccessActor;
use App\Core\Access\AccessLevel;
use App\Core\Id\UuidFactory;
use App\Core\Log\AuditLoggerInterface;
use App\Core\Log\MessageLoggerInterface;
use App\Core\Message\Message;
use App\Core\Message\MessageException;
use App\Core\State\StateMarkerKey;
use App\Core\State\StateMarkerRecorder;
use App\Core\State\StateSubjectType;
use App\Entity\AccountToken;
use App\Entity\AclGroup;
use App\Entity\UserAccount;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Throwable;

final class AccountLinkAcceptanceService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly MessageLoggerInterface $messageLogger,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly StateMarkerRecorder $stateMarkers,
        private readonly UuidFactory $uuidFactory,
        private readonly UserGroupMembershipManager $userGroups,
    ) {
    }

    public function accept(AccountToken $token, string $username, string $password): UserAccount
    {
        if ($token->isExpired() || AccountTokenStatus::Pending !== $token->status()) {
            $this->rejectAccountLink('token_unavailable');
        }

        if ($token->role()->accessLevel() < AccessLevel::USER) {
            $this->rejectAccountLink('non_login_role');
        }

        $isNewUser = !$token->user() instanceof UserAccount;
        $user = $this->userForAccountToken($token, $username);
        $user->changePassword($this->passwordHasher->hashPassword($user, $password));
        $user->changeRole($token->role());
        $user->changeStatus(UserAccountStatus::Active);
        $this->replaceGroups($user, $token);
        $token->consume($user);
        $this->entityManager->persist($user);

        if ($isNewUser) {
            $this->stateMarkers->record(StateSubjectType::USER_ACCOUNT, $user->uid(), StateMarkerKey::CREATED, 'account_link', $token->type()->value);
        }

        $this->stateMarkers->record(StateSubjectType::USER_ACCOUNT, $user->uid(), StateMarkerKey::PASSWORD_CHANGED, 'account_link', $token->type()->value);
        $this->stateMarkers->record(StateSubjectType::USER_ACCOUNT, $user->uid(), StateMarkerKey::STATUS_CHANGED, 'account_link', UserAccountStatus::Active->value);
        $this->entityManager->flush();
        $this->audit($user, 'user.invitation_accepted', ['token_type' => $token->type()->value]);

        return $user;
    }

    private function userForAccountToken(AccountToken $token, string $username): UserAccount
    {
        $existingTokenUser = $token->user();
        $existingEmailUser = $this->userByEmail($token->email());
        $existingUsernameUser = $this->userByUsername($username);

        if ($existingTokenUser instanceof UserAccount) {
            if (UserAccountStatus::Deleted !== $existingTokenUser->status()) {
                $this->rejectAccountLink('token_user_status');
            }

            if ($existingEmailUser instanceof UserAccount && $existingEmailUser !== $existingTokenUser) {
                $this->rejectDuplicateEmail($token->email());
            }

            if ($existingUsernameUser instanceof UserAccount && $existingUsernameUser !== $existingTokenUser) {
                $this->rejectDuplicateUsername($username);
            }

            $existingTokenUser->changeUsername($username);
            $existingTokenUser->changeEmail($token->email());

            return $existingTokenUser;
        }

        if ($existingEmailUser instanceof UserAccount) {
            $this->rejectDuplicateEmail($token->email());
        }

        if ($existingUsernameUser instanceof UserAccount) {
            $this->rejectDuplicateUsername($username);
        }

        return new UserAccount($this->uuidFactory->generate(), $username, $token->email(), '', role: $token->role());
    }

    private function replaceGroups(UserAccount $user, AccountToken $token): void
    {
        $groupIdentifiers = $token->groupIdentifiers();

        if ([] === $groupIdentifiers) {
            $user->clearGroups();

            return;
        }

        $groups = $this->userGroups->groups($groupIdentifiers);
        $resolvedIdentifiers = array_map(static fn (AclGroup $group): string => $group->identifier(), $groups);
        $missingIdentifiers = array_values(array_diff($groupIdentifiers, $resolvedIdentifiers));

        $this->logStaleTokenGroups($token, $user, $missingIdentifiers);

        foreach ($groups as $group) {
            if ($token->role()->accessLevel() < $group->minRole()) {
                $this->rejectAccountLink('group_role_floor', ['group' => $group->identifier()]);
            }
        }

        $user->clearGroups();

        foreach ($groups as $group) {
            $user->addGroup($group);
        }
    }

    /**
     * @param list<string> $missingIdentifiers
     */
    private function logStaleTokenGroups(AccountToken $token, UserAccount $user, array $missingIdentifiers): void
    {
        if ([] === $missingIdentifiers) {
            return;
        }

        try {
            $this->messageLogger->log(Message::warning(
                SecurityMessageCode::ACCOUNT_LINK_STALE_GROUPS,
                SecurityMessageKey::ACCOUNT_LINK_STALE_GROUPS,
                context: [
                    'token_uid' => $token->uid(),
                    'token_type' => $token->type()->value,
                    'user_uid' => $user->uid(),
                    'email' => $user->email(),
                    'missing_groups' => $missingIdentifiers,
                ],
            ));
        } catch (Throwable) {
            return;
        }
    }

    private function userByUsername(string $username): ?UserAccount
    {
        $user = $this->entityManager->getRepository(UserAccount::class)->findOneBy(['username' => $username]);

        return $user instanceof UserAccount ? $user : null;
    }

    private function userByEmail(string $email): ?UserAccount
    {
        $user = $this->entityManager->getRepository(UserAccount::class)->findOneByEmail($email);

        return $user instanceof UserAccount ? $user : null;
    }

    private function rejectDuplicateEmail(string $email): never
    {
        throw MessageException::forMessage(SecurityMessageCode::USER_EMAIL_DUPLICATE, SecurityMessageKey::USER_EMAIL_DUPLICATE, [
            '%value%' => $email,
        ], [
            'field' => 'email',
        ]);
    }

    private function rejectDuplicateUsername(string $username): never
    {
        throw MessageException::forMessage(SecurityMessageCode::USER_USERNAME_DUPLICATE, SecurityMessageKey::USER_USERNAME_DUPLICATE, [
            '%value%' => $username,
        ], [
            'field' => 'username',
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function rejectAccountLink(string $reason, array $context = []): never
    {
        throw MessageException::forMessage(SecurityMessageCode::ACCOUNT_LINK_INVALID, SecurityMessageKey::ACCOUNT_LINK_INVALID, context: [
            'reason' => $reason,
            ...$context,
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function audit(UserAccount $user, string $action, array $context): void
    {
        try {
            $this->auditLogger->log(AccessActor::fromUserAccount($user), $action, $context);
        } catch (Throwable) {
            return;
        }
    }
}
