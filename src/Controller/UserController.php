<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Access\AccessActor;
use App\Core\Log\AuditLoggerInterface;
use App\Core\Message\MessageException;
use App\Core\Routing\AbsoluteUriGenerator;
use App\Core\State\StateMarkerKey;
use App\Core\State\StateMarkerRecorder;
use App\Core\State\StateSubjectType;
use App\Core\Validation\EmailAddress;
use App\Entity\AccountToken;
use App\Entity\UserAccount;
use App\Localization\LocalePreferenceResolver;
use App\Mail\AccountMailFlow;
use App\Mail\MailLocaleResolver;
use App\Security\AccountLinkDeliveryInterface;
use App\Security\AccountTokenIssuer;
use App\Security\AccountTokenMaintenance;
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
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Throwable;

final class UserController extends AbstractController
{
    public function __construct(
        private readonly HttpErrorRenderer $httpError,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly UserFlowConfig $userFlowConfig,
        private readonly AccountTokenIssuer $tokenIssuer,
        private readonly AccountLinkDeliveryInterface $linkDelivery,
        private readonly AbsoluteUriGenerator $absoluteUris,
        private readonly MailLocaleResolver $mailLocaleResolver,
        private readonly UserAccountLifecycle $userLifecycle,
        private readonly AccountTokenMaintenance $tokenMaintenance,
        private readonly AdminUserAccessPolicy $adminUserPolicy,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly StateMarkerRecorder $stateMarkers,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly LocalePreferenceResolver $localePreferences,
    ) {
    }

    #[Route('/user', name: 'user_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->currentUser() instanceof UserAccount) {
            return $this->httpError->unauthorized($request);
        }

        return $this->redirectToRoute('user_profile');
    }

    #[Route('/user/profile', name: 'user_profile', methods: ['GET', 'POST'])]
    public function profile(Request $request): Response
    {
        $user = $this->currentUser();

        if (!$user instanceof UserAccount) {
            return $this->httpError->unauthorized($request);
        }

        $success = false;
        $errors = [];
        $usernameChangeEnabled = $this->userFlowConfig->usernameChangeEnabled();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('user_profile', $this->stringField($request, '_csrf_token'))) {
                $errors[] = 'ui.user.profile.errors.invalid_csrf';
            }

            if ([] === $errors) {
                if ($usernameChangeEnabled) {
                    $newUsername = $this->stringField($request, 'username');
                    $existingUsernameUser = $this->userByUsername($newUsername);

                    if ($existingUsernameUser instanceof UserAccount && $existingUsernameUser !== $user) {
                        $errors[] = 'ui.user.profile.errors.username_in_use';
                    } else {
                        try {
                            $user->changeUsername($newUsername);
                        } catch (Throwable) {
                            $errors[] = 'ui.user.profile.errors.username_invalid';
                        }
                    }
                }
            }

            if ([] === $errors) {
                $newEmail = EmailAddress::normalize($this->stringField($request, 'email'));
                $oldEmail = $user->email();

                if (!EmailAddress::isValid($newEmail)) {
                    $errors[] = 'ui.user.profile.errors.email_invalid';
                } else {
                    $existingEmailUser = $this->userByEmail($newEmail);

                    if ($existingEmailUser instanceof UserAccount && $existingEmailUser !== $user) {
                        $errors[] = 'ui.user.profile.errors.email_in_use';
                    } else {
                        try {
                            if ($newEmail !== $oldEmail) {
                                $this->tokenMaintenance->revokePendingForUser($user, [AccountTokenType::PasswordReset, AccountTokenType::SecurityReview]);
                            }

                            $user->changeEmail($newEmail);
                        } catch (MessageException) {
                            $errors[] = 'ui.user.profile.errors.email_invalid';
                        }
                    }
                }
            }

            if ([] === $errors) {
                $user->updateProfile([
                    'display_name' => $this->stringField($request, 'display_name'),
                ]);
                $user->updateSettings([
                    ...$user->settings(),
                    'language' => $this->stringField($request, 'language') ?: 'default',
                ]);
                try {
                    $this->stateMarkers->record(StateSubjectType::USER_ACCOUNT, $user->uid(), StateMarkerKey::MODIFIED, $user->username(), 'profile');
                    $this->entityManager->flush();
                    $this->audit($user, 'user.profile_updated', ['result_status' => 'success']);
                    $this->applyProfileLocale($request, $user);
                    $this->addFlash('success', 'ui.user.profile.success');

                    return $this->redirectToRoute('user_profile');
                } catch (MessageException $exception) {
                    $errors[] = $exception->messageKey();
                    $this->audit($user, 'user.profile_update_failed', ['result_status' => 'failed', 'error_key' => $exception->messageKey()]);
                }
            }
        }

        return $this->render('@frontend/user/profile.html.twig', [
            'user_account' => $user,
            'username_change_enabled' => $usernameChangeEnabled,
            'success' => $success,
            'errors' => $errors,
        ]);
    }

    private function applyProfileLocale(Request $request, UserAccount $user): void
    {
        $locale = $this->localePreferences->resolveProfileLocale($user);

        $request->setLocale($locale);

        try {
            $request->getSession()->set('_locale', $locale);
        } catch (SessionNotFoundException) {
        }
        $this->localeSwitcher->setLocale($locale);
    }

    #[Route('/user/profile/close', name: 'user_profile_close', methods: ['GET', 'POST'])]
    public function closeProfile(Request $request): Response
    {
        $user = $this->currentUser();

        if (!$user instanceof UserAccount) {
            return $this->httpError->unauthorized($request);
        }

        if (!$request->isMethod('POST')) {
            return $this->render('@frontend/user/profile-close.html.twig', [
                'deleted_user_retention_days' => $this->userFlowConfig->deletedUserRetentionDays(),
            ]);
        }

        $errors = [];

        if (!$this->isCsrfTokenValid('user_profile_close', $this->stringField($request, '_csrf_token'))) {
            $errors[] = 'ui.user.profile.close.errors.invalid_csrf';
        }

        if ('1' !== $this->stringField($request, 'confirm_close')) {
            $errors[] = 'ui.user.profile.close.errors.confirmation';
        }

        if (!$this->passwordHasher->isPasswordValid($user, $this->stringField($request, 'password'))) {
            $errors[] = 'ui.user.profile.close.errors.password';
        }

        if (!$this->adminUserPolicy->allowsAccountClosure($user)) {
            $errors[] = 'ui.user.profile.close.errors.last_owner';
        }

        if ([] !== $errors) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }

            $this->audit($user, 'user.account_close_failed', [
                'result_status' => 'failed',
                'error_keys' => $errors,
            ]);

            return $this->redirectToRoute('user_profile_close');
        }

        $retentionDays = $this->userFlowConfig->deletedUserRetentionDays();
        $effects = $this->userLifecycle->changeStatus($user, UserAccountStatus::Deleted, $user->username());
        $this->entityManager->flush();
        $this->linkDelivery->notifyAddress(
            $user->email(),
            AccountMailFlow::AccountClosed,
            $this->mailLocaleResolver->forPublicRequest($request, $user),
            [
                'username' => $user->username(),
                'user_uid' => $user->uid(),
                'retention_days' => $retentionDays,
            ],
        );
        $this->audit($user, 'user.account_closed', ['result_status' => 'success', ...$effects]);
        $this->tokenStorage->setToken(null);
        $request->getSession()->invalidate();

        return $this->redirectToRoute('content_home');
    }

    #[Route('/user/password', name: 'user_password', methods: ['GET', 'POST'])]
    public function password(Request $request): Response
    {
        $user = $this->currentUser();

        if (!$user instanceof UserAccount) {
            return $this->httpError->unauthorized($request);
        }

        $errors = [];
        $success = false;

        if ($request->isMethod('POST')) {
            $currentPassword = $this->stringField($request, 'current_password');
            $newPassword = $this->stringField($request, 'new_password');
            $confirmPassword = $this->stringField($request, 'confirm_password');

            if (!$this->isCsrfTokenValid('user_password_change', $this->stringField($request, '_csrf_token'))) {
                $errors[] = 'ui.user.password.errors.invalid_csrf';
            }

            if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
                $errors[] = 'ui.user.password.errors.current_password';
            }

            $errors = [
                ...$errors,
                ...$this->passwordViolationKeys($newPassword, $user->username(), $user->email()),
            ];

            if ($newPassword !== $confirmPassword) {
                $errors[] = 'ui.user.password.errors.password_mismatch';
            }

            if ([] === $errors) {
                [$token, $plainToken] = $this->issuePasswordChangeReviewToken($user);
                $reviewUrl = $this->passwordChangeReviewUrl($plainToken);

                if (null === $reviewUrl) {
                    $errors[] = 'ui.user.password.errors.delivery_failed';
                } else {
                    $user->changePassword($this->passwordHasher->hashPassword($user, $newPassword));
                    $this->stateMarkers->record(StateSubjectType::USER_ACCOUNT, $user->uid(), StateMarkerKey::PASSWORD_CHANGED, $user->username(), 'profile');
                    $this->entityManager->flush();
                    $this->deliverPasswordChangeNotification($request, $token, $plainToken, $reviewUrl);
                    $this->audit($user, 'auth.password_change_success', ['result_status' => 'success']);
                    $success = true;
                }
            }

            if ([] !== $errors) {
                $this->audit($user, 'auth.password_change_failed', [
                    'result_status' => 'failed',
                    'error_keys' => $errors,
                ]);
            }
        }

        return $this->render('@frontend/user/password.html.twig', [
            'errors' => $errors,
            'success' => $success,
            'user_account' => $user,
        ]);
    }

    private function currentUser(): ?UserAccount
    {
        $user = $this->getUser();

        return $user instanceof UserAccount ? $user : null;
    }

    private function stringField(Request $request, string $name): string
    {
        $value = $request->request->get($name);

        return is_string($value) ? $value : '';
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
