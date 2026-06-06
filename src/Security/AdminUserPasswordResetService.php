<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Access\AccessActor;
use App\Core\Log\AuditLoggerInterface;
use App\Core\Routing\AbsoluteUriGenerator;
use App\Entity\UserAccount;
use App\Mail\AccountMailFlow;
use App\Mail\MailLocaleResolver;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final readonly class AdminUserPasswordResetService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AccountTokenIssuer $tokenIssuer,
        private AccountTokenMaintenance $tokenMaintenance,
        private AccountLinkDeliveryInterface $linkDelivery,
        private AbsoluteUriGenerator $absoluteUris,
        private MailLocaleResolver $mailLocaleResolver,
        private AdminUserAccessPolicy $adminUserPolicy,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    public function create(AccessActor $actor, UserAccount $user): AdminAccountTokenActionResult
    {
        if (!$user->status()->isUsable()) {
            return AdminAccountTokenActionResult::error('admin.users.invitation.unavailable');
        }

        if ($error = $this->adminUserPolicy->validateUserAction($actor, $user)) {
            return AdminAccountTokenActionResult::error($error);
        }

        $this->tokenMaintenance->revokePendingForUser($user, [AccountTokenType::PasswordReset]);
        [$token, $plainToken] = $this->tokenIssuer->issue(AccountTokenType::PasswordReset, $user->email(), [], $user, ttl: UserFlowConfig::PASSWORD_RESET_TTL);
        $url = $this->absoluteUris->generateUri(__METHOD__, 'user_password_reset_token', ['token' => $plainToken]);

        if (null === $url) {
            return AdminAccountTokenActionResult::error('admin.users.form.errors.mail_delivery_failed');
        }

        $this->entityManager->persist($token);
        $this->entityManager->flush();
        $this->linkDelivery->deliver($token, AccountMailFlow::PasswordResetLink, $url, $this->mailLocaleResolver->forAdminAction($user));
        $this->audit($actor, 'user.password_reset_created', ['target_user' => $user->uid(), 'token_uid' => $token->uid()]);

        return AdminAccountTokenActionResult::success('admin.users.password_reset.created');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function audit(AccessActor $actor, string $action, array $context): void
    {
        try {
            $this->auditLogger->log($actor, $action, $context);
        } catch (Throwable) {
            return;
        }
    }
}
