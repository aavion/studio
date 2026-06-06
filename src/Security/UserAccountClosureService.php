<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Access\AccessActor;
use App\Core\Log\AuditLoggerInterface;
use App\Entity\UserAccount;
use App\Mail\AccountMailFlow;
use App\Mail\MailLocaleResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Throwable;

final readonly class UserAccountClosureService
{
    public function __construct(
        private UserPasswordHasherInterface $passwordHasher,
        private EntityManagerInterface $entityManager,
        private UserFlowConfig $userFlowConfig,
        private UserAccountLifecycle $userLifecycle,
        private AccountLinkDeliveryInterface $linkDelivery,
        private MailLocaleResolver $mailLocaleResolver,
        private AdminUserAccessPolicy $adminUserPolicy,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    public function close(Request $request, UserAccount $user, string $password, bool $confirmed): UserAccountClosureResult
    {
        $errors = [];

        if (!$confirmed) {
            $errors[] = 'ui.user.profile.close.errors.confirmation';
        }

        if (!$this->passwordHasher->isPasswordValid($user, $password)) {
            $errors[] = 'ui.user.profile.close.errors.password';
        }

        if (!$this->adminUserPolicy->allowsAccountClosure($user)) {
            $errors[] = 'ui.user.profile.close.errors.last_owner';
        }

        if ([] !== $errors) {
            return UserAccountClosureResult::failed($errors);
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

        return UserAccountClosureResult::success();
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
