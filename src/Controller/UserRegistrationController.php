<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Access\AccessActor;
use App\Core\Access\AccessLevel;
use App\Core\Id\UuidFactory;
use App\Core\Log\AuditLoggerInterface;
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

                if (!$defaultGroup instanceof AclGroup) {
                    $errors[] = 'ui.user.register.errors.default_group';
                }
            }

            if ([] === $errors) {
                $this->tokenMaintenance->revokePendingForEmail($email, [AccountTokenType::Invitation, AccountTokenType::Registration]);
                [$token, $plainToken] = $this->tokenIssuer->issue(
                    AccountTokenType::Registration,
                    $email,
                    [$defaultGroup->identifier()],
                    UserAccountStatus::Deleted === $existingUser?->status() ? $existingUser : null,
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

        if (!$accountToken instanceof AccountToken || AccountTokenType::PasswordReset === $accountToken->type()) {
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
                    $isNewUser = !$accountToken->user() instanceof UserAccount;
                    $user = $this->userForAccountToken($accountToken, $username);
                    $user->changePassword($this->passwordHasher->hashPassword($user, $password));
                    $user->changeStatus(UserAccountStatus::Active);
                    $this->replaceGroups($user, $accountToken->groupIdentifiers());
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

        return $group instanceof AclGroup && $group->accessLevel() >= AccessLevel::REGISTERED ? $group : null;
    }

    private function userForAccountToken(AccountToken $token, string $username): UserAccount
    {
        $existingTokenUser = $token->user();
        $existingUsernameUser = $this->userByUsername($username);

        if ($existingTokenUser instanceof UserAccount) {
            if (UserAccountStatus::Deleted !== $existingTokenUser->status()) {
                throw new \RuntimeException('Account token user is not deleted.');
            }

            if ($existingUsernameUser instanceof UserAccount && $existingUsernameUser !== $existingTokenUser) {
                throw new \RuntimeException('Username is already assigned.');
            }

            $existingTokenUser->changeUsername($username);
            $existingTokenUser->changeEmail($token->email());

            return $existingTokenUser;
        }

        if ($existingUsernameUser instanceof UserAccount) {
            throw new \RuntimeException('Username is already assigned.');
        }

        return new UserAccount($this->uuidFactory->v4(), $username, $token->email(), '');
    }

    /**
     * @param list<string> $groupIdentifiers
     */
    private function replaceGroups(UserAccount $user, array $groupIdentifiers): void
    {
        $groups = $this->entityManager->getRepository(AclGroup::class)->findBy(['identifier' => $groupIdentifiers]);
        $maxAccessLevel = 0;

        foreach ($groups as $group) {
            if ($group instanceof AclGroup) {
                $maxAccessLevel = max($maxAccessLevel, $group->accessLevel());
            }
        }

        if ([] === $groups || $maxAccessLevel < AccessLevel::REGISTERED) {
            throw new \RuntimeException('Account token does not assign registered access.');
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
