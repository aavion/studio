<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiFeaturePolicy;
use App\Core\Access\AccessActor;
use App\Core\Log\AuditLoggerInterface;
use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Message\MessageException;
use App\Core\State\StateMarkerKey;
use App\Core\State\StateMarkerRecorder;
use App\Core\State\StateSubjectType;
use App\Core\Validation\EmailAddress;
use App\Entity\UserAccount;
use App\Localization\UserProfileLocaleService;
use App\Security\AccountTokenMaintenance;
use App\Security\AccountTokenType;
use App\Security\UserAccountClosureService;
use App\Security\UserAccountStatus;
use App\Security\UserFlowConfig;
use App\Security\UserPasswordChangeService;
use App\View\Http\HttpErrorRenderer;
use App\View\Alert\MercureAvailability;
use App\View\Alert\UiAlertDelivery;
use App\View\Alert\UiAlertDispatcherInterface;
use App\View\Alert\UiAlertTranslation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Throwable;

final class UserController extends AbstractController
{
    public function __construct(
        private readonly HttpErrorRenderer $httpError,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly UserFlowConfig $userFlowConfig,
        private readonly AccountTokenMaintenance $tokenMaintenance,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly StateMarkerRecorder $stateMarkers,
        private readonly UserPasswordChangeService $passwordChangeService,
        private readonly UserAccountClosureService $accountClosureService,
        private readonly UserProfileLocaleService $profileLocales,
        private readonly ApiFeaturePolicy $apiFeaturePolicy,
        private readonly MercureAvailability $mercureAvailability,
        private readonly UiAlertDispatcherInterface $alerts,
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
        $nativeNotificationsAvailable = $this->mercureAvailability->available(refreshIfStale: true);

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
                $language = $this->stringField($request, 'language') ?: 'default';

                if ('default' !== $language && !in_array($language, $this->profileLocales->availableLocales(), true)) {
                    $errors[] = 'ui.user.profile.errors.language_invalid';
                }
            }

            if ([] === $errors) {
                $user->updateProfile([
                    'display_name' => $this->stringField($request, 'display_name'),
                ]);
                $user->updateSettings([
                    ...$user->settings(),
                    'language' => $language,
                    'native_notifications' => $nativeNotificationsAvailable && '1' === $this->stringField($request, 'native_notifications'),
                ]);
                try {
                    $this->stateMarkers->record(StateSubjectType::USER_ACCOUNT, $user->uid(), StateMarkerKey::MODIFIED, $user->username(), 'profile');
                    $this->entityManager->flush();
                    $this->audit($user, 'user.profile_updated', ['result_status' => 'success']);
                    $this->profileLocales->apply($request, $user);
                    $this->alertKey('success', 'ui.user.profile.success');

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
            'language_options' => $this->profileLocales->options(),
            'api_key_management_enabled' => $this->apiFeaturePolicy->canManageKeys($user),
            'native_notifications_available' => $nativeNotificationsAvailable,
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

        if ([] === $errors) {
            $result = $this->accountClosureService->close(
                $request,
                $user,
                $this->stringField($request, 'password'),
                '1' === $this->stringField($request, 'confirm_close'),
            );
            $errors = $result->errors();
        }

        if ([] !== $errors) {
            foreach ($errors as $error) {
                $this->alertKey('error', $error);
            }

            $this->audit($user, 'user.account_close_failed', [
                'result_status' => 'failed',
                'error_keys' => $errors,
            ]);

            return $this->redirectToRoute('user_profile_close');
        }

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

            if ([] === $errors) {
                $result = $this->passwordChangeService->change($request, $user, $currentPassword, $newPassword, $confirmPassword);
                $errors = $result->errors();
                $success = $result->successState();
            } else {
                $this->audit($user, 'auth.password_change_failed', ['result_status' => 'failed', 'error_keys' => $errors]);
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

    private function alertKey(string $level, string $key): void
    {
        $this->alerts->addAlert(UiAlertTranslation::forLevel($level, $key), UiAlertDelivery::Direct);
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
