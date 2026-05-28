<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Access\AccessActor;
use App\Core\Log\AuditLoggerInterface;
use App\Entity\AccountToken;
use App\Entity\AclGroup;
use App\Entity\ApiKey;
use App\Entity\UserAccount;
use App\Mail\AccountMailFlow;
use App\Mail\MailLocaleResolver;
use App\Security\AccountLinkDeliveryInterface;
use App\Security\AccountTokenIssuer;
use App\Security\AccountTokenStatus;
use App\Security\AccountTokenType;
use App\Security\ApiKeyStatus;
use App\Security\ApiKeyVault;
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
        private readonly MailLocaleResolver $mailLocaleResolver,
        private readonly ApiKeyVault $apiKeyVault,
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

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('user_profile', $this->stringField($request, '_csrf_token'))) {
                $errors[] = 'ui.user.profile.errors.invalid_csrf';
            }

            if ([] === $errors) {
                $user->updateProfile([
                    'display_name' => $this->stringField($request, 'display_name'),
                ]);
                $user->updateSettings([
                    ...$user->settings(),
                    'language' => $this->stringField($request, 'language') ?: 'default',
                ]);
                $this->entityManager->flush();
                $this->audit($user, 'user.profile_updated', ['result_status' => 'success']);
                $success = true;
            }
        }

        return $this->render('@frontend/user/profile.html.twig', [
            'user_account' => $user,
            'success' => $success,
            'errors' => $errors,
        ]);
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

    #[Route('/user/api-keys', name: 'user_api_keys', methods: ['GET', 'POST'])]
    public function apiKeys(Request $request): Response
    {
        $user = $this->currentUser();

        if (!$user instanceof UserAccount) {
            return $this->httpError->unauthorized($request);
        }

        $newPlainKey = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('user_api_key_create', $this->stringField($request, '_csrf_token'))) {
                $this->addFlash('error', 'ui.user.api_keys.errors.invalid_csrf');
            } else {
                $prefix = $this->stringField($request, 'prefix');
                $plainKey = $this->apiKeyVault->generatePlainKey($prefix);
                $status = '1' === $this->stringField($request, 'read_only') ? ApiKeyStatus::ReadOnly : ApiKeyStatus::ReadWrite;

                try {
                    $apiKey = new ApiKey(self::uuid(), $prefix, $this->apiKeyVault->hmac($plainKey), $this->apiKeyVault->encrypt($plainKey), $user, $status);
                    $this->entityManager->persist($apiKey);
                    $this->entityManager->flush();
                    $this->audit($user, 'api_key.created', ['api_key_uid' => $apiKey->uid(), 'prefix' => $apiKey->prefix(), 'status' => $status->value]);
                    $newPlainKey = $plainKey;
                } catch (Throwable) {
                    $this->addFlash('error', 'ui.user.api_keys.errors.create_failed');
                }
            }
        }

        return $this->render('@frontend/user/api-keys.html.twig', [
            'api_keys' => $this->entityManager->getRepository(ApiKey::class)->findBy(
                ['user' => $user],
                ['createdAt' => 'DESC', 'prefix' => 'ASC'],
            ),
            'new_plain_api_key' => $newPlainKey,
        ]);
    }

    #[Route('/user/api-keys/{uid}/reveal', name: 'user_api_key_reveal', requirements: ['uid' => '[a-f0-9-]{36}'], methods: ['GET', 'POST'])]
    public function revealApiKey(Request $request, string $uid): Response
    {
        $user = $this->currentUser();

        if (!$user instanceof UserAccount) {
            return $this->httpError->unauthorized($request);
        }

        $apiKey = $this->entityManager->find(ApiKey::class, $uid);

        if (!$apiKey instanceof ApiKey || $apiKey->user() !== $user) {
            return $this->httpError->notFound($request);
        }

        $plainKey = null;
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('user_api_key_reveal_'.$uid, $this->stringField($request, '_csrf_token'))) {
                $errors[] = 'ui.user.api_keys.errors.invalid_csrf';
            }

            if (!$this->passwordHasher->isPasswordValid($user, $this->stringField($request, 'password'))) {
                $errors[] = 'ui.user.api_keys.errors.password';
            }

            if ([] === $errors) {
                $plainKey = $this->apiKeyVault->decrypt($apiKey->encryptedKey());
                $this->audit($user, 'api_key.revealed', ['api_key_uid' => $apiKey->uid(), 'prefix' => $apiKey->prefix()]);

                if (null === $plainKey) {
                    $errors[] = 'ui.user.api_keys.errors.decrypt_failed';
                }
            }
        }

        return $this->render('@frontend/user/api-key-reveal.html.twig', [
            'api_key' => $apiKey,
            'plain_api_key' => $plainKey,
            'errors' => $errors,
        ]);
    }

    #[Route('/user/api-keys/{uid}/revoke', name: 'user_api_key_revoke', requirements: ['uid' => '[a-f0-9-]{36}'], methods: ['POST'])]
    public function revokeApiKey(Request $request, string $uid): Response
    {
        $user = $this->currentUser();

        if (!$user instanceof UserAccount) {
            return $this->httpError->unauthorized($request);
        }

        $apiKey = $this->entityManager->find(ApiKey::class, $uid);

        if ($apiKey instanceof ApiKey && $apiKey->user() === $user && $this->isCsrfTokenValid('user_api_key_revoke_'.$uid, $this->stringField($request, '_csrf_token'))) {
            $apiKey->revoke();
            $this->entityManager->flush();
            $this->audit($user, 'api_key.revoked', ['api_key_uid' => $apiKey->uid(), 'prefix' => $apiKey->prefix()]);
        }

        return $this->redirectToRoute('user_api_keys');
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

            $email = $this->stringField($request, 'email');

            if ('' === $email || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'ui.user.register.errors.email';
            }

            if ([] === $errors) {
                $existingUser = $this->userByEmail($email);

                if ($existingUser instanceof UserAccount) {
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

                $this->revokePendingTokensForEmail($email, [AccountTokenType::Invitation, AccountTokenType::Registration]);
                [$token, $plainToken] = $this->tokenIssuer->issue(
                    AccountTokenType::Registration,
                    $email,
                    ['registered'],
                    status: $requiresApproval ? AccountTokenStatus::PendingApproval : AccountTokenStatus::Pending,
                    ttl: $this->userFlowConfig->accountLinkTtl(),
                );
                $this->entityManager->persist($token);
                $this->entityManager->flush();

                if (!$requiresApproval) {
                    $this->linkDelivery->deliver($token, AccountMailFlow::RegistrationLink, $plainToken, $this->generateUrl('user_invitation_accept', ['token' => $plainToken], 0), $this->mailLocaleResolver->forPublicRequest($request));
                } else {
                    $this->linkDelivery->notify(
                        $token,
                        AccountMailFlow::RegistrationApprovalRequested,
                        $this->userFlowConfig->registrationAdminNotificationEmail(),
                        $this->mailLocaleResolver->defaultLocale(),
                    );
                }

                $success = true;
            }
        }

        return $this->render('@frontend/user/register.html.twig', [
            'success' => $success,
            'requires_approval' => $requiresApproval,
            'errors' => $errors,
        ]);
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
                $email = strtolower($this->stringField($request, 'email'));
                $user = $this->entityManager->getRepository(UserAccount::class)->findOneBy(['email' => $email]);

                if ($user instanceof UserAccount) {
                    $this->revokePendingTokensForUser($user, [AccountTokenType::PasswordReset]);
                    [$token, $plainToken] = $this->tokenIssuer->issue(AccountTokenType::PasswordReset, $user->email(), [], $user, ttl: UserFlowConfig::PASSWORD_RESET_TTL);
                    $this->entityManager->persist($token);
                    $this->entityManager->flush();
                    $this->linkDelivery->deliver($token, AccountMailFlow::PasswordResetLink, $plainToken, $this->generateUrl('user_password_reset_token', ['token' => $plainToken], 0), $this->mailLocaleResolver->forPublicRequest($request, $user));
                }

                $success = true;
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

        if (!$accountToken instanceof AccountToken || !$accountToken->user() instanceof UserAccount) {
            return $this->httpError->notFound($request);
        }

        return $this->completePasswordToken($request, $accountToken);
    }

    #[Route('/user/security-review/{token}', name: 'user_security_review', requirements: ['token' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function securityReview(Request $request, string $token): Response
    {
        $accountToken = $this->usableToken($token, AccountTokenType::SecurityReview);
        $locked = false;

        if ($accountToken instanceof AccountToken && $accountToken->user() instanceof UserAccount) {
            $user = $accountToken->user();
            $user->changeStatus(UserAccountStatus::Inactive);
            $accountToken->consume($user);
            $this->entityManager->flush();
            $this->linkDelivery->notify($accountToken, AccountMailFlow::PasswordChangeDisputed, $this->userFlowConfig->securityNotificationEmail(), $this->mailLocaleResolver->defaultLocale(), [
                'username' => $user->username(),
                'user_uid' => $user->uid(),
            ]);
            $this->audit($user, 'auth.password_change_disputed', ['result_status' => 'locked']);
            $locked = true;
        }

        return $this->render('@frontend/user/security-review.html.twig', [
            'locked' => $locked,
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
                    $user = new UserAccount(self::uuid(), $username, $accountToken->email(), '');
                    $user->changePassword($this->passwordHasher->hashPassword($user, $password));
                    $user->changeStatus(UserAccountStatus::Active);
                    $this->assignGroups($user, $accountToken->groupIdentifiers());
                    $accountToken->consume($user);
                    $this->entityManager->persist($user);
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

    private function completePasswordToken(Request $request, AccountToken $token): Response
    {
        $errors = [];
        $success = false;
        $user = $token->user();

        if ($request->isMethod('POST') && $user instanceof UserAccount) {
            $password = $this->stringField($request, 'password');
            $confirmPassword = $this->stringField($request, 'confirm_password');

            if (!$this->isCsrfTokenValid('user_password_reset_'.$token->uid(), $this->stringField($request, '_csrf_token'))) {
                $errors[] = 'ui.user.password_reset.errors.invalid_csrf';
            }

            if (12 > strlen($password)) {
                $errors[] = 'ui.user.password.errors.new_password_length';
            }

            if ($password !== $confirmPassword) {
                $errors[] = 'ui.user.password.errors.password_mismatch';
            }

            if ([] === $errors) {
                $user->changePassword($this->passwordHasher->hashPassword($user, $password));
                [$reviewToken, $plainReviewToken] = $this->issuePasswordChangeReviewToken($user);
                $token->consume($user);
                $this->entityManager->flush();
                $this->deliverPasswordChangeNotification($request, $reviewToken, $plainReviewToken);
                $this->audit($user, 'auth.password_reset_completed', ['result_status' => 'success']);
                $success = true;
            }
        }

        return $this->render('@frontend/user/password-reset-complete.html.twig', [
            'account_token' => $token,
            'success' => $success,
            'errors' => $errors,
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

    /**
     * @return array{0: AccountToken, 1: string}
     */
    private function issuePasswordChangeReviewToken(UserAccount $user): array
    {
        $this->revokePendingTokensForUser($user, [AccountTokenType::SecurityReview]);
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
        $this->linkDelivery->deliver(
            $token,
            AccountMailFlow::PasswordChanged,
            $plainToken,
            $this->generateUrl('user_security_review', ['token' => $plainToken], 0),
            $this->mailLocaleResolver->forPublicRequest($request, $token->user()),
        );
    }

    /**
     * @param list<AccountTokenType> $types
     */
    private function revokePendingTokensForEmail(string $email, array $types): void
    {
        $tokens = $this->entityManager->getRepository(AccountToken::class)->findBy([
            'email' => strtolower($email),
            'type' => $types,
            'status' => [AccountTokenStatus::Pending, AccountTokenStatus::PendingApproval],
        ]);

        foreach ($tokens as $token) {
            if ($token instanceof AccountToken) {
                $token->revoke();
            }
        }
    }

    /**
     * @param list<AccountTokenType> $types
     */
    private function revokePendingTokensForUser(UserAccount $user, array $types): void
    {
        $tokens = $this->entityManager->getRepository(AccountToken::class)->findBy([
            'user' => $user,
            'type' => $types,
            'status' => AccountTokenStatus::Pending,
        ]);

        foreach ($tokens as $token) {
            if ($token instanceof AccountToken) {
                $token->revoke();
            }
        }
    }

    private function userByEmail(string $email): ?UserAccount
    {
        $user = $this->entityManager->getRepository(UserAccount::class)->findOneBy(['email' => strtolower($email)]);

        return $user instanceof UserAccount ? $user : null;
    }

    /**
     * @param list<string> $groupIdentifiers
     */
    private function assignGroups(UserAccount $user, array $groupIdentifiers): void
    {
        $groups = $this->entityManager->getRepository(AclGroup::class)->findBy(['identifier' => $groupIdentifiers]);

        foreach ($groups as $group) {
            if ($group instanceof AclGroup) {
                $user->addGroup($group);
            }
        }
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

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
