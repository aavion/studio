<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Access\AccessActor;
use App\Core\Log\AuditLoggerInterface;
use App\Core\State\StateMarkerKey;
use App\Core\State\StateMarkerRecorder;
use App\Core\State\StateSubjectType;
use App\Core\Routing\AbsoluteUriGenerator;
use App\Core\Validation\EmailAddress;
use App\Entity\AccountToken;
use App\Entity\UserAccount;
use App\Mail\AccountMailFlow;
use App\Mail\MailLocaleResolver;
use App\Security\AccountLinkDeliveryInterface;
use App\Security\AccountTokenIssuer;
use App\Security\AccountTokenMaintenance;
use App\Security\AccountTokenStatus;
use App\Security\AccountTokenType;
use App\Security\AdminUserAccessPolicy;
use App\Security\PasswordPolicy;
use App\Security\UserAccountLifecycle;
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

final class UserPasswordRecoveryController extends AbstractController
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
        private readonly UserAccountLifecycle $userLifecycle,
        private readonly AdminUserAccessPolicy $adminUserPolicy,
        private readonly StateMarkerRecorder $stateMarkers,
        private readonly PasswordPolicy $passwordPolicy,
    ) {
    }

    #[Route('/user/reset-password', name: 'user_reset_password', methods: ['GET', 'POST'])]
    public function resetPassword(Request $request): Response
    {
        $success = false;
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('user_password_reset', $this->stringField($request, '_csrf_token'))) {
                $errors[] = 'ui.user.password_reset.errors.invalid_csrf';
            }

            if ([] === $errors) {
                $canCreateResetUrl = null !== $this->absoluteUris->generateUri(__METHOD__.'.preflight', 'user_password_reset_token', ['token' => str_repeat('0', 64)]);

                if (!$canCreateResetUrl) {
                    $errors[] = 'ui.user.password_reset.errors.delivery_failed';
                }
            }

            if ([] === $errors) {
                $email = EmailAddress::normalize($this->stringField($request, 'email'));
                $user = $this->entityManager->getRepository(UserAccount::class)->findOneByEmail($email);

                if ($user instanceof UserAccount && $user->status()->isUsable()) {
                    $this->tokenMaintenance->revokePendingForUser($user, [AccountTokenType::PasswordReset]);
                    [$token, $plainToken] = $this->tokenIssuer->issue(AccountTokenType::PasswordReset, $user->email(), [], $user, ttl: UserFlowConfig::PASSWORD_RESET_TTL);
                    $url = $this->absoluteUris->generateUri(__METHOD__, 'user_password_reset_token', ['token' => $plainToken]);

                    if (null === $url) {
                        $errors[] = 'ui.user.password_reset.errors.delivery_failed';
                    } else {
                        $this->entityManager->persist($token);
                        $this->entityManager->flush();
                        $this->linkDelivery->deliver($token, AccountMailFlow::PasswordResetLink, $plainToken, $url, $this->mailLocaleResolver->forPublicRequest($request, $user));
                    }
                }

                $success = [] === $errors;
            }
        }

        return $this->render('@frontend/user/password-reset.html.twig', [
            'success' => $success,
            'errors' => $errors,
        ]);
    }

    #[Route('/user/reset-password/{token}', name: 'user_password_reset_token', requirements: ['token' => '[a-f0-9]{64}'], methods: ['GET', 'POST'])]
    public function completePasswordReset(Request $request, string $token): Response
    {
        $accountToken = $this->usableToken($token, AccountTokenType::PasswordReset);

        if (!$accountToken instanceof AccountToken || !$this->hasUsableTokenUser($accountToken)) {
            return $this->httpError->notFound($request);
        }

        return $this->completePasswordToken($request, $accountToken);
    }

    #[Route('/user/security-review/{token}', name: 'user_security_review', requirements: ['token' => '[a-f0-9]{64}'], methods: ['GET', 'POST'])]
    public function securityReview(Request $request, string $token): Response
    {
        $accountToken = $this->usableToken($token, AccountTokenType::SecurityReview);

        if (!$accountToken instanceof AccountToken || !$this->hasUsableTokenUser($accountToken)) {
            return $this->httpError->notFound($request);
        }

        if (!$request->isMethod('POST')) {
            return $this->render('@frontend/user/security-review.html.twig', [
                'account_token' => $accountToken,
                'confirm' => true,
                'locked' => false,
                'errors' => [],
            ]);
        }

        if (!$this->isCsrfTokenValid('user_security_review_'.$accountToken->uid(), $this->stringField($request, '_csrf_token'))) {
            return $this->render('@frontend/user/security-review.html.twig', [
                'account_token' => $accountToken,
                'confirm' => true,
                'locked' => false,
                'errors' => ['ui.user.security_review.errors.invalid_csrf'],
            ]);
        }

        $user = $accountToken->user();
        if (!$user instanceof UserAccount || !$user->status()->isUsable() || $accountToken->isExpired() || AccountTokenStatus::Pending !== $accountToken->status()) {
            return $this->httpError->notFound($request);
        }

        if (!$this->adminUserPolicy->allowsSecurityReviewLock($user)) {
            return $this->render('@frontend/user/security-review.html.twig', [
                'account_token' => $accountToken,
                'confirm' => true,
                'locked' => false,
                'errors' => ['ui.user.security_review.errors.last_owner'],
            ]);
        }

        $accountToken->consume($user);
        $effects = $this->userLifecycle->changeStatus($user, UserAccountStatus::Inactive, $user->username());
        $this->entityManager->flush();
        $this->linkDelivery->notify($accountToken, AccountMailFlow::PasswordChangeDisputed, $this->userFlowConfig->securityNotificationEmail(), $this->mailLocaleResolver->defaultLocale(), [
            'username' => $user->username(),
            'user_uid' => $user->uid(),
        ]);
        $this->audit($user, 'auth.password_change_disputed', ['result_status' => 'locked', ...$effects]);

        return $this->render('@frontend/user/security-review.html.twig', [
            'account_token' => $accountToken,
            'confirm' => false,
            'locked' => true,
            'errors' => [],
        ]);
    }

    private function completePasswordToken(Request $request, AccountToken $token): Response
    {
        $errors = [];
        $success = false;
        $user = $token->user();

        if ($request->isMethod('POST') && $user instanceof UserAccount) {
            if (!$user->status()->isUsable() || $token->isExpired() || AccountTokenStatus::Pending !== $token->status()) {
                return $this->httpError->notFound($request);
            }

            $password = $this->stringField($request, 'password');
            $confirmPassword = $this->stringField($request, 'confirm_password');

            if (!$this->isCsrfTokenValid('user_password_reset_'.$token->uid(), $this->stringField($request, '_csrf_token'))) {
                $errors[] = 'ui.user.password_reset.errors.invalid_csrf';
            }

            $errors = [
                ...$errors,
                ...$this->passwordViolationKeys($password, $user->username(), $user->email()),
            ];

            if ($password !== $confirmPassword) {
                $errors[] = 'ui.user.password.errors.password_mismatch';
            }

            if ([] === $errors) {
                [$reviewToken, $plainReviewToken] = $this->issuePasswordChangeReviewToken($user);
                $reviewUrl = $this->passwordChangeReviewUrl($plainReviewToken);

                if (null === $reviewUrl) {
                    $errors[] = 'ui.user.password.errors.delivery_failed';
                } else {
                    $user->changePassword($this->passwordHasher->hashPassword($user, $password));
                    $token->consume($user);
                    $this->stateMarkers->record(StateSubjectType::USER_ACCOUNT, $user->uid(), StateMarkerKey::PASSWORD_CHANGED, $user->username(), 'password_reset');
                    $this->entityManager->flush();
                    $this->deliverPasswordChangeNotification($request, $reviewToken, $plainReviewToken, $reviewUrl);
                    $this->audit($user, 'auth.password_reset_completed', ['result_status' => 'success']);
                    $success = true;
                }
            }
        }

        return $this->render('@frontend/user/password-reset-complete.html.twig', [
            'account_token' => $token,
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

    private function hasUsableTokenUser(AccountToken $token): bool
    {
        $user = $token->user();

        return $user instanceof UserAccount && $user->status()->isUsable();
    }

    /**
     * @return list<string>
     */
    private function passwordViolationKeys(string $password, string $username, string $email): array
    {
        return array_map(
            static fn (string $violation): string => match ($violation) {
                PasswordPolicy::VIOLATION_COMPLEXITY => 'ui.user.password.errors.new_password_complexity',
                PasswordPolicy::VIOLATION_REPEATED => 'ui.user.password.errors.new_password_repeated',
                PasswordPolicy::VIOLATION_PERSONAL => 'ui.user.password.errors.new_password_personal',
                default => 'ui.user.password.errors.new_password_length',
            },
            $this->passwordPolicy->violationCodes($password, $username, $email),
        );
    }

    /**
     * @return array{0: AccountToken, 1: string}
     */
    private function issuePasswordChangeReviewToken(UserAccount $user): array
    {
        $this->tokenMaintenance->revokePendingForUser($user, [AccountTokenType::SecurityReview]);
        [$token, $plainToken] = $this->tokenIssuer->issue(
            AccountTokenType::SecurityReview,
            $user->email(),
            [],
            $user,
            ttl: $this->userFlowConfig->accountLinkTtl(),
        );
        $this->entityManager->persist($token);

        return [$token, $plainToken];
    }

    private function passwordChangeReviewUrl(string $plainToken): ?string
    {
        return $this->absoluteUris->generateUri(__METHOD__, 'user_security_review', ['token' => $plainToken]);
    }

    private function deliverPasswordChangeNotification(Request $request, AccountToken $token, string $plainToken, string $url): void
    {
        $this->linkDelivery->deliver(
            $token,
            AccountMailFlow::PasswordChanged,
            $plainToken,
            $url,
            $this->mailLocaleResolver->forPublicRequest($request, $token->user()),
        );
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
