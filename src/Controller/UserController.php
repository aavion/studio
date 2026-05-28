<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Access\AccessActor;
use App\Core\Log\AuditLoggerInterface;
use App\Core\Routing\AbsoluteUriGenerator;
use App\Core\State\StateMarkerKey;
use App\Core\State\StateMarkerRecorder;
use App\Core\State\StateSubjectType;
use App\Entity\AccountToken;
use App\Entity\UserAccount;
use App\Mail\AccountMailFlow;
use App\Mail\MailLocaleResolver;
use App\Security\AccountLinkDeliveryInterface;
use App\Security\AccountTokenIssuer;
use App\Security\AccountTokenMaintenance;
use App\Security\AccountTokenType;
use App\Security\AdminUserAccessPolicy;
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
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
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
                $user->updateProfile([
                    'display_name' => $this->stringField($request, 'display_name'),
                ]);
                $user->updateSettings([
                    ...$user->settings(),
                    'language' => $this->stringField($request, 'language') ?: 'default',
                ]);
                $this->stateMarkers->record(StateSubjectType::USER_ACCOUNT, $user->uid(), StateMarkerKey::MODIFIED, $user->username(), 'profile');
                $this->entityManager->flush();
                $this->audit($user, 'user.profile_updated', ['result_status' => 'success']);
                $success = true;
            }
        }

        return $this->render('@frontend/user/profile.html.twig', [
            'user_account' => $user,
            'username_change_enabled' => $usernameChangeEnabled,
            'success' => $success,
            'errors' => $errors,
        ]);
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
            $errors[] = 'ui.user.profile.close.errors.last_admin';
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

            if (12 > strlen($newPassword)) {
                $errors[] = 'ui.user.password.errors.new_password_length';
            }

            if ($newPassword !== $confirmPassword) {
                $errors[] = 'ui.user.password.errors.password_mismatch';
            }

            if ([] === $errors) {
                $user->changePassword($this->passwordHasher->hashPassword($user, $newPassword));
                [$token, $plainToken] = $this->issuePasswordChangeReviewToken($user);
                $this->stateMarkers->record(StateSubjectType::USER_ACCOUNT, $user->uid(), StateMarkerKey::PASSWORD_CHANGED, $user->username(), 'profile');
                $this->entityManager->flush();
                $this->deliverPasswordChangeNotification($request, $token, $plainToken);
                $this->audit($user, 'auth.password_change_success', ['result_status' => 'success']);
                $success = true;
            } else {
                $this->audit($user, 'auth.password_change_failed', [
                    'result_status' => 'failed',
                    'error_keys' => $errors,
                ]);
            }
        }

        return $this->render('@frontend/user/password.html.twig', [
            'errors' => $errors,
            'success' => $success,
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

    private function deliverPasswordChangeNotification(Request $request, AccountToken $token, string $plainToken): void
    {
        $url = $this->absoluteUris->generateUri(__METHOD__, 'user_security_review', ['token' => $plainToken]);

        if (null === $url) {
            return;
        }

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
