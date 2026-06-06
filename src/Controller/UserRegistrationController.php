<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Access\AccessLevel;
use App\Core\Message\MessageException;
use App\Core\Routing\AbsoluteUriGenerator;
use App\Core\Validation\EmailAddress;
use App\Entity\AccountToken;
use App\Entity\AclGroup;
use App\Entity\UserAccount;
use App\Mail\AccountMailFlow;
use App\Mail\MailLocaleResolver;
use App\Security\AccountLinkAcceptanceService;
use App\Security\AccountLinkDeliveryInterface;
use App\Security\AccountReactivationAccessResolver;
use App\Security\AccountTokenIssuer;
use App\Security\AccountTokenLookup;
use App\Security\AccountTokenMaintenance;
use App\Security\AccountTokenStatus;
use App\Security\AccountTokenType;
use App\Security\PasswordPolicyErrorMapper;
use App\Security\UserAccountStatus;
use App\Security\UserFlowConfig;
use App\Security\UserRole;
use App\View\Http\HttpErrorRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class UserRegistrationController extends AbstractController
{
    public function __construct(
        private readonly HttpErrorRenderer $httpError,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserFlowConfig $userFlowConfig,
        private readonly AccountTokenIssuer $tokenIssuer,
        private readonly AccountTokenLookup $tokenLookup,
        private readonly AccountTokenMaintenance $tokenMaintenance,
        private readonly AccountLinkDeliveryInterface $linkDelivery,
        private readonly AbsoluteUriGenerator $absoluteUris,
        private readonly MailLocaleResolver $mailLocaleResolver,
        private readonly AccountReactivationAccessResolver $reactivationAccess,
        private readonly PasswordPolicyErrorMapper $passwordErrors,
        private readonly AccountLinkAcceptanceService $accountLinkAcceptance,
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
            $existingUser = null;
            $tokenGroups = [];
            $tokenRole = UserRole::User;

            if (!$this->isCsrfTokenValid('user_register', $this->stringField($request, '_csrf_token'))) {
                $errors[] = 'ui.user.register.errors.invalid_csrf';
            }

            $email = EmailAddress::normalize($this->stringField($request, 'email'));

            if (!EmailAddress::isValid($email)) {
                $errors[] = 'ui.user.register.errors.email';
            }

            if ([] === $errors) {
                $existingUser = $this->userByEmail($email);

                if ($existingUser instanceof UserAccount && UserAccountStatus::Deleted !== $existingUser->status()) {
                    if (!$requiresApproval && !$this->canCreateRegistrationLink()) {
                        $errors[] = 'ui.user.register.errors.delivery_failed';
                    } else {
                        $this->linkDelivery->notifyAddress(
                            $existingUser->email(),
                            AccountMailFlow::RegistrationExistingAccount,
                            $this->mailLocaleResolver->forPublicRequest($request, $existingUser),
                            ['username' => $existingUser->username()],
                        );
                        $success = true;
                    }

                    return $this->render('@frontend/user/register.html.twig', [
                        'success' => $success,
                        'requires_approval' => $requiresApproval,
                        'errors' => $errors,
                    ]);
                }

                $defaultGroup = $this->defaultRegistrationGroup();
                $tokenRole = $existingUser instanceof UserAccount ? $this->reactivationAccess->role($existingUser) : UserRole::User;
                $tokenGroups = $existingUser instanceof UserAccount
                    ? $this->reactivationAccess->groupIdentifiers($existingUser, $tokenRole)
                    : ($defaultGroup instanceof AclGroup ? [$defaultGroup->identifier()] : []);
                $requiresApproval = $requiresApproval || $this->requiresElevatedReactivationApproval($existingUser, $tokenRole);
            }

            if ([] === $errors && !$requiresApproval) {
                if (!$this->canCreateRegistrationLink()) {
                    $errors[] = 'ui.user.register.errors.delivery_failed';
                }
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
                        $this->linkDelivery->deliver($token, AccountMailFlow::RegistrationLink, $url, $this->mailLocaleResolver->forPublicRequest($request));
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
        $accountToken = $this->tokenLookup->pending($token);

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

            $errors = [
                ...$errors,
                ...$this->passwordErrors->errorKeys($password, $username, $accountToken->email()),
            ];

            if ($password !== $confirmPassword) {
                $errors[] = 'ui.user.password.errors.password_mismatch';
            }

            if ([] === $errors) {
                try {
                    $this->accountLinkAcceptance->accept($accountToken, $username, $password);
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

    private function userByEmail(string $email): ?UserAccount
    {
        $user = $this->entityManager->getRepository(UserAccount::class)->findOneByEmail($email);

        return $user instanceof UserAccount ? $user : null;
    }

    private function defaultRegistrationGroup(): ?AclGroup
    {
        $identifier = $this->userFlowConfig->defaultAclGroupIdentifier();

        if (null === $identifier) {
            return null;
        }

        $group = $this->entityManager->getRepository(AclGroup::class)->findOneBy([
            'identifier' => $identifier,
        ]);

        return $group instanceof AclGroup && $group->minRole() <= AccessLevel::USER ? $group : null;
    }

    private function canCreateRegistrationLink(): bool
    {
        return null !== $this->absoluteUris->generateUri(__METHOD__.'.preflight', 'user_invitation_accept', ['token' => str_repeat('0', 64)]);
    }

    private function requiresElevatedReactivationApproval(?UserAccount $existingUser, UserRole $tokenRole): bool
    {
        return $existingUser instanceof UserAccount
            && UserAccountStatus::Deleted === $existingUser->status()
            && $tokenRole->accessLevel() > AccessLevel::USER;
    }

    private function stringField(Request $request, string $name): string
    {
        $value = $request->request->get($name);

        return is_string($value) ? $value : '';
    }

}
