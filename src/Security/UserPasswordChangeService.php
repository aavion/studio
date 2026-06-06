<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Access\AccessActor;
use App\Core\Log\AuditLoggerInterface;
use App\Core\Routing\AbsoluteUriGenerator;
use App\Core\State\StateMarkerKey;
use App\Core\State\StateMarkerRecorder;
use App\Core\State\StateSubjectType;
use App\Entity\UserAccount;
use App\Mail\AccountMailFlow;
use App\Mail\MailLocaleResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Throwable;

final readonly class UserPasswordChangeService
{
    public function __construct(
        private UserPasswordHasherInterface $passwordHasher,
        private EntityManagerInterface $entityManager,
        private UserFlowConfig $userFlowConfig,
        private AccountTokenIssuer $tokenIssuer,
        private AccountTokenMaintenance $tokenMaintenance,
        private AccountLinkDeliveryInterface $linkDelivery,
        private AbsoluteUriGenerator $absoluteUris,
        private MailLocaleResolver $mailLocaleResolver,
        private StateMarkerRecorder $stateMarkers,
        private PasswordPolicyErrorMapper $passwordErrors,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    public function change(Request $request, UserAccount $user, string $currentPassword, string $newPassword, string $confirmPassword): UserPasswordChangeResult
    {
        $errors = [];

        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            $errors[] = 'ui.user.password.errors.current_password';
        }

        $errors = [
            ...$errors,
            ...$this->passwordErrors->errorKeys($newPassword, $user->username(), $user->email()),
        ];

        if ($newPassword !== $confirmPassword) {
            $errors[] = 'ui.user.password.errors.password_mismatch';
        }

        if ([] !== $errors) {
            $this->audit($user, 'auth.password_change_failed', [
                'result_status' => 'failed',
                'error_keys' => $errors,
            ]);

            return UserPasswordChangeResult::failed($errors);
        }

        $this->tokenMaintenance->revokePendingForUser($user, [AccountTokenType::SecurityReview]);
        [$token, $plainToken] = $this->tokenIssuer->issue(
            AccountTokenType::SecurityReview,
            $user->email(),
            [],
            $user,
            ttl: $this->userFlowConfig->accountLinkTtl(),
        );
        $reviewUrl = $this->absoluteUris->generateUri(__METHOD__, 'user_security_review', ['token' => $plainToken]);

        if (null === $reviewUrl) {
            $errors[] = 'ui.user.password.errors.delivery_failed';
            $this->audit($user, 'auth.password_change_failed', [
                'result_status' => 'failed',
                'error_keys' => $errors,
            ]);

            return UserPasswordChangeResult::failed($errors);
        }

        $this->entityManager->persist($token);
        $user->changePassword($this->passwordHasher->hashPassword($user, $newPassword));
        $this->stateMarkers->record(StateSubjectType::USER_ACCOUNT, $user->uid(), StateMarkerKey::PASSWORD_CHANGED, $user->username(), 'profile');
        $this->entityManager->flush();
        $this->linkDelivery->deliver(
            $token,
            AccountMailFlow::PasswordChanged,
            $reviewUrl,
            $this->mailLocaleResolver->forPublicRequest($request, $token->user()),
        );
        $this->audit($user, 'auth.password_change_success', ['result_status' => 'success']);

        return UserPasswordChangeResult::success();
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
