<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Access\AccessActor;
use App\Core\Access\AccessLevel;
use App\Core\Id\UuidFactory;
use App\Core\Log\AuditLoggerInterface;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageException;
use App\Core\Message\MessageKey;
use App\Core\State\StateMarkerKey;
use App\Core\State\StateMarkerRecorder;
use App\Core\State\StateSubjectType;
use App\Core\Routing\AbsoluteUriGenerator;
use App\Core\Validation\EmailAddress;
use App\Entity\AccountToken;
use App\Entity\AclGroup;
use App\Entity\UserAccount;
use App\Mail\AccountMailFlow;
use App\Mail\MailLocaleResolver;
use App\Security\AccountLinkDeliveryInterface;
use App\Security\AccountTokenIssuer;
use App\Security\AccountTokenMaintenance;
use App\Security\AccountTokenStatus;
use App\Security\AccountTokenType;
use App\Security\UserAccountStatus;
use App\Security\UserFlowConfig;
use App\Security\UserRole;
use App\View\Http\HttpErrorRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class UserRegistrationController extends AbstractController
{
    public function __construct(
        private readonly HttpErrorRenderer $httpError,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly UserFlowConfig $userFlowConfig,
        private readonly AccountTokenIssuer $tokenIssuer,
        private readonly AccountTokenMaintenance $tokenMaintenance,
        private readonly AccountLinkDeliveryInterface $linkDelivery,
        private readonly AbsoluteUriGenerator $absoluteUris,
        private readonly MailLocaleResolver $mailLocaleResolver,
        private readonly StateMarkerRecorder $stateMarkers,
        private readonly UuidFactory $uuidFactory,
    ) {
    }

    #[Route('/user/register', name: 'user_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        if (!$this->userFlowConfig->registrationEnabled()) {
            return $this->httpError->notFound($request);
        }

        $success = false;
        $requiresApproval = UserFlowConfig::REGISTRATION_ADMIN_APPROVAL === $this->userFlowConfig->registrationMode();
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('user_register', $this->stringField($request, '_csrf_token'))) {
                $errors[] = 'ui.user.register.errors.invalid_csrf';
            }

            $email = EmailAddress::normalize($this->stringField($request, 'email'));

            if (!EmailAddress::isValid($email)) {
                $errors[] = 'ui.user.register.errors.email';
            }

            if ([] === $errors && !$requiresApproval) {
                $canCreateRegistrationUrl = null !== $this->absoluteUris->generateUri(__METHOD__.'.preflight', 'user_invitation_accept', ['token' => str_repeat('0', 64)]);

                if (!$canCreateRegistrationUrl) {
                    $errors[] = 'ui.user.register.errors.delivery_failed';
                }
            }

            if ([] === $errors) {
                $existingUser = $this->userByEmail($email);

                if ($existingUser instanceof UserAccount && UserAccountStatus::Deleted !== $existingUser->status()) {
                    $this->linkDelivery->notifyAddress(
                        $existingUser->email(),
                        AccountMailFlow::RegistrationExistingAccount,
                        $this->mailLocaleResolver->forPublicRequest($request, $existingUser),
                        ['username' => $existingUser->username()],
                    );
                    $success = true;

                    return $this->render('@frontend/user/register.html.twig', [
                        'success' => $success,
                        'requires_approval' => $requiresApproval,
                        'errors' => $errors,
                    ]);
                }

                $defaultGroup = $this->defaultRegistrationGroup();
                $tokenRole = $existingUser instanceof UserAccount ? $this->reactivationRole($existingUser) : UserRole::User;
                $tokenGroups = $existingUser instanceof UserAccount
                    ? $this->reactivationGroupIdentifiers($existingUser, $tokenRole)
                    : ($defaultGroup instanceof AclGroup ? [$defaultGroup->identifier()] : []);
            }

            if ([] === $errors) {
                $this->tokenMaintenance->revokePendingForEmail($email, [AccountTokenType::Invitation, AccountTokenType::Registration]);
                [$token, $plainToken] = $this->tokenIssuer->issue(
                    AccountTokenType::Registration,
                    $email,
                    $tokenGroups,
                    UserAccountStatus::Deleted === $existingUser?->status() ? $existingUser : null,
                    role: $tokenRole,
                    status: $requiresApproval ? AccountTokenStatus::PendingApproval : AccountTokenStatus::Pending,
                    ttl: $this->userFlowConfig->accountLinkTtl(),
                );

                if (!$requiresApproval) {
                    $url = $this->absoluteUris->generateUri(__METHOD__, 'user_invitation_accept', ['token' => $plainToken]);

                    if (null === $url) {
                        $errors[] = 'ui.user.register.errors.delivery_failed';
                    } else {
                        $this->entityManager->persist($token);
                        $this->entityManager->flush();
                        $this->linkDelivery->deliver($token, AccountMailFlow::RegistrationLink, $plainToken, $url, $this->mailLocaleResolver->forPublicRequest($request));
                        $success = true;
                    }
                } else {
                    $this->entityManager->persist($token);
                    $this->entityManager->flush();
                    $this->linkDelivery->notify(
                        $token,
                        AccountMailFlow::RegistrationApprovalRequested,
                        $this->userFlowConfig->registrationAdminNotificationEmail(),
                        $this->mailLocaleResolver->defaultLocale(),
                    );
                    $success = true;
                }
            }
        }

        return $this->render('@frontend/user/register.html.twig', [
            'success' => $success,
            'requires_approval' => $requiresApproval,
            'errors' => $errors,
        ]);
    }

    #[Route('/user/invitation/{token}', name: 'user_invitation_accept', requirements: ['token' => '[a-f0-9]{64}'], methods: ['GET', 'POST'])]
    public function invitation(Request $request, string $token): Response
    {
        $accountToken = $this->usableToken($token, null);

        if (!$accountToken instanceof AccountToken || !in_array($accountToken->type(), [AccountTokenType::Invitation, AccountTokenType::Registration], true)) {
            return $this->httpError->notFound($request);
        }

        $errors = [];
        $success = false;

        if ($request->isMethod('POST')) {
            $username = $this->stringField($request, 'username');
            $password = $this->stringField($request, 'password');
            $confirmPassword = $this->stringField($request, 'confirm_password');

            if (!$this->isCsrfTokenValid('user_invitation_'.$accountToken->uid(), $this->stringField($request, '_csrf_token'))) {
                $errors[] = 'ui.user.invitation.errors.invalid_csrf';
            }

            if (12 > strlen($password)) {
                $errors[] = 'ui.user.password.errors.new_password_length';
            }

            if ($password !== $confirmPassword) {
                $errors[] = 'ui.user.password.errors.password_mismatch';
            }

            if ([] === $errors) {
                try {
                    if ($accountToken->isExpired() || AccountTokenStatus::Pending !== $accountToken->status()) {
                        return $this->httpError->notFound($request);
                    }

                    if ($accountToken->role()->accessLevel() < AccessLevel::USER) {
                        $this->rejectAccountLink('non_login_role');
                    }

                    $isNewUser = !$accountToken->user() instanceof UserAccount;
                    $user = $this->userForAccountToken($accountToken, $username);
                    $user->changePassword($this->passwordHasher->hashPassword($user, $password));
                    $user->changeRole($accountToken->role());
                    $user->changeStatus(UserAccountStatus::Active);
                    $this->replaceGroups($user, $accountToken->groupIdentifiers(), $accountToken->role());
                    $accountToken->consume($user);
                    $this->entityManager->persist($user);
                    if ($isNewUser) {
                        $this->stateMarkers->record(StateSubjectType::USER_ACCOUNT, $user->uid(), StateMarkerKey::CREATED, 'account_link', $accountToken->type()->value);
                    }
                    $this->stateMarkers->record(StateSubjectType::USER_ACCOUNT, $user->uid(), StateMarkerKey::PASSWORD_CHANGED, 'account_link', $accountToken->type()->value);
                    $this->stateMarkers->record(StateSubjectType::USER_ACCOUNT, $user->uid(), StateMarkerKey::STATUS_CHANGED, 'account_link', UserAccountStatus::Active->value);
                    $this->entityManager->flush();
                    $this->audit($user, 'user.invitation_accepted', ['token_type' => $accountToken->type()->value]);
                    $success = true;
                } catch (MessageException $exception) {
                    $errors[] = [
                        'translation_key' => $exception->messageKey(),
                        'parameters' => $exception->parameters(),
                    ];
                } catch (Throwable) {
                    $errors[] = 'ui.user.invitation.errors.create_failed';
                }
            }
        }

        return $this->render('@frontend/user/invitation.html.twig', [
            'account_token' => $accountToken,
            'success' => $success,
            'errors' => $errors,
        ]);
    }

    private function usableToken(string $plainToken, ?AccountTokenType $type): ?AccountToken
    {
        $criteria = [
            'tokenHash' => $this->tokenIssuer->hash($plainToken),
            'status' => AccountTokenStatus::Pending,
        ];

        if ($type instanceof AccountTokenType) {
            $criteria['type'] = $type;
        }

        $token = $this->entityManager->getRepository(AccountToken::class)->findOneBy($criteria);

        return $token instanceof AccountToken && !$token->isExpired() ? $token : null;
    }

    private function userByEmail(string $email): ?UserAccount
    {
        $user = $this->entityManager->getRepository(UserAccount::class)->findOneByEmail($email);

        return $user instanceof UserAccount ? $user : null;
    }

    private function userByUsername(string $username): ?UserAccount
    {
        $user = $this->entityManager->getRepository(UserAccount::class)->findOneBy(['username' => $username]);

        return $user instanceof UserAccount ? $user : null;
    }

    private function defaultRegistrationGroup(): ?AclGroup
    {
        $group = $this->entityManager->getRepository(AclGroup::class)->findOneBy([
            'identifier' => $this->userFlowConfig->defaultAclGroupIdentifier(),
        ]);

        return $group instanceof AclGroup && $group->minRole() <= AccessLevel::USER ? $group : null;
    }

    private function reactivationRole(UserAccount $user): UserRole
    {
        return UserRole::Public === $user->role() ? UserRole::User : $user->role();
    }

    /**
     * @return list<string>
     */
    private function reactivationGroupIdentifiers(UserAccount $user, UserRole $role): array
    {
        $identifiers = [];

        foreach ($user->groups() as $group) {
            if ($group instanceof AclGroup && $role->accessLevel() >= $group->minRole()) {
                $identifiers[] = $group->identifier();
            }
        }

        sort($identifiers);

        return array_values(array_unique($identifiers));
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

        return new UserAccount($this->uuidFactory->v4(), $username, $token->email(), '', role: $token->role());
    }

    /**
     * @param list<string> $groupIdentifiers
     */
    private function replaceGroups(UserAccount $user, array $groupIdentifiers, UserRole $role): void
    {
        if ([] === $groupIdentifiers) {
            $user->clearGroups();

            return;
        }

        $groups = $this->entityManager->getRepository(AclGroup::class)->findBy(['identifier' => $groupIdentifiers]);
        foreach ($groups as $group) {
            if ($group instanceof AclGroup) {
                if ($role->accessLevel() < $group->minRole()) {
                    $this->rejectAccountLink('group_role_floor', ['group' => $group->identifier()]);
                }
            }
        }

        $user->clearGroups();

        foreach ($groups as $group) {
            if ($group instanceof AclGroup) {
                $user->addGroup($group);
            }
        }
    }

    private function stringField(Request $request, string $name): string
    {
        $value = $request->request->get($name);

        return is_string($value) ? $value : '';
    }

    private function rejectDuplicateEmail(string $email): never
    {
        throw MessageException::forMessage(MessageCode::USER_EMAIL_DUPLICATE, MessageKey::USER_EMAIL_DUPLICATE, [
            '%value%' => $email,
        ], [
            'field' => 'email',
        ]);
    }

    private function rejectDuplicateUsername(string $username): never
    {
        throw MessageException::forMessage(MessageCode::USER_USERNAME_DUPLICATE, MessageKey::USER_USERNAME_DUPLICATE, [
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
        throw MessageException::forMessage(MessageCode::ACCOUNT_LINK_INVALID, MessageKey::ACCOUNT_LINK_INVALID, context: [
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
