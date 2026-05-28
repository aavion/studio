<?php

declare(strict_types=1);

namespace App\Controller;

use App\Backend\BackendAccessGuard;
use App\Backend\BackendArea;
use App\Core\Access\AccessActor;
use App\Core\Log\AuditLoggerInterface;
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
use App\Security\UserAccountStatus;
use App\Security\UserFlowConfig;
use App\View\Http\HttpErrorRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class AdminUserInvitationController extends AbstractController
{
    public function __construct(
        private readonly BackendAccessGuard $accessGuard,
        private readonly HttpErrorRenderer $httpError,
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountTokenIssuer $tokenIssuer,
        private readonly AccountTokenMaintenance $tokenMaintenance,
        private readonly AccountLinkDeliveryInterface $linkDelivery,
        private readonly MailLocaleResolver $mailLocaleResolver,
        private readonly UserFlowConfig $userFlowConfig,
        private readonly AdminUserAccessPolicy $adminUserPolicy,
        private readonly AuditLoggerInterface $auditLogger,
    ) {
    }

    #[Route('/admin/users/invitations', name: 'backend_admin_user_invite', priority: 10, methods: ['POST'])]
    public function invite(Request $request): Response
    {
        if ($response = $this->adminAccessResponse($request)) {
            return $response;
        }

        if (!$this->isCsrfTokenValid('admin_user_invite', $this->field($request, '_csrf_token'))) {
            $this->addFlash('error', 'admin.users.form.errors.invalid_csrf');

            return $this->redirectAfterTokenAction($request);
        }

        $email = $this->field($request, 'email');
        $groups = $this->groupIdentifiers($request->request->all('groups'));

        try {
            if ($error = $this->adminUserPolicy->validateGroupAssignment($this->actor(), $groups)) {
                $this->addFlash('error', $error);

                return $this->redirectToRoute('backend_admin_users');
            }

            $existingUser = $this->userByEmail($email);

            if ($existingUser instanceof UserAccount && UserAccountStatus::Deleted !== $existingUser->status()) {
                $this->addFlash('error', 'admin.users.form.errors.email_in_use');

                return $this->redirectToRoute('backend_admin_users');
            }

            $this->tokenMaintenance->revokePendingForEmail($email, [AccountTokenType::Invitation, AccountTokenType::Registration]);
            [$token, $plainToken] = $this->tokenIssuer->issue(
                AccountTokenType::Invitation,
                $email,
                $groups,
                UserAccountStatus::Deleted === $existingUser?->status() ? $existingUser : null,
                ttl: $this->userFlowConfig->accountLinkTtl(),
            );
            $this->entityManager->persist($token);
            $this->entityManager->flush();
            $this->linkDelivery->deliver($token, AccountMailFlow::InvitationLink, $plainToken, $this->generateUrl('user_invitation_accept', ['token' => $plainToken], 0), $this->mailLocaleResolver->forAdminAction());
            $this->audit('user.invitation_created', ['email' => $email, 'groups' => $groups, 'token_uid' => $token->uid()]);
            $this->addFlash('success', 'admin.users.invitation.created');
        } catch (Throwable) {
            $this->addFlash('error', 'admin.users.form.errors.invalid_invitation');
        }

        return $this->redirectToRoute('backend_admin_users');
    }

    #[Route('/admin/users/invitations/{uid}/approve', name: 'backend_admin_user_invitation_approve', priority: 10, methods: ['POST'])]
    public function approve(Request $request, string $uid): Response
    {
        if ($response = $this->adminAccessResponse($request)) {
            return $response;
        }

        if (!$this->isCsrfTokenValid('admin_user_token_'.$uid, $this->field($request, '_csrf_token'))) {
            $this->addFlash('error', 'admin.users.form.errors.invalid_csrf');

            return $this->redirectAfterTokenAction($request);
        }

        $token = $this->entityManager->find(AccountToken::class, $uid);

        if (!$token instanceof AccountToken || AccountTokenStatus::PendingApproval !== $token->status()) {
            $this->addFlash('error', 'admin.users.invitation.unavailable');

            return $this->redirectAfterTokenAction($request);
        }

        $plainToken = $this->tokenIssuer->reissue($token, $this->userFlowConfig->accountLinkTtl());
        $token->approve();
        $this->entityManager->flush();
        $this->linkDelivery->notify($token, AccountMailFlow::RegistrationApproved, locale: $this->mailLocaleResolver->forAdminAction($token->user()));
        $this->linkDelivery->deliver($token, AccountMailFlow::RegistrationLink, $plainToken, $this->generateUrl('user_invitation_accept', ['token' => $plainToken], 0), $this->mailLocaleResolver->forAdminAction($token->user()));
        $this->audit('user.registration_approved', ['email' => $token->email(), 'token_uid' => $token->uid()]);
        $this->addFlash('success', 'admin.users.invitation.approved');

        return $this->redirectAfterTokenAction($request);
    }

    #[Route('/admin/users/invitations/{uid}/reissue', name: 'backend_admin_user_invitation_reissue', priority: 10, methods: ['POST'])]
    public function reissue(Request $request, string $uid): Response
    {
        if ($response = $this->adminAccessResponse($request)) {
            return $response;
        }

        if (!$this->isCsrfTokenValid('admin_user_token_'.$uid, $this->field($request, '_csrf_token'))) {
            $this->addFlash('error', 'admin.users.form.errors.invalid_csrf');

            return $this->redirectAfterTokenAction($request);
        }

        $token = $this->entityManager->find(AccountToken::class, $uid);

        if (!$token instanceof AccountToken || AccountTokenStatus::Pending !== $token->status()) {
            $this->addFlash('error', 'admin.users.invitation.unavailable');

            return $this->redirectAfterTokenAction($request);
        }

        $plainToken = $this->tokenIssuer->reissue($token, $this->ttlForToken($token));
        $this->entityManager->flush();
        $this->linkDelivery->deliver($token, $this->flowForToken($token), $plainToken, $this->urlForToken($token, $plainToken), $this->mailLocaleResolver->forAdminAction($token->user()));
        $this->audit('user.account_token_reissued', ['email' => $token->email(), 'token_uid' => $token->uid(), 'token_type' => $token->type()->value]);
        $this->addFlash('success', 'admin.users.invitation.reissued');

        return $this->redirectAfterTokenAction($request);
    }

    #[Route('/admin/users/invitations/{uid}/revoke', name: 'backend_admin_user_invitation_revoke', priority: 10, methods: ['POST'])]
    public function revoke(Request $request, string $uid): Response
    {
        if ($response = $this->adminAccessResponse($request)) {
            return $response;
        }

        if (!$this->isCsrfTokenValid('admin_user_token_'.$uid, $this->field($request, '_csrf_token'))) {
            $this->addFlash('error', 'admin.users.form.errors.invalid_csrf');

            return $this->redirectAfterTokenAction($request);
        }

        $token = $this->entityManager->find(AccountToken::class, $uid);

        if ($token instanceof AccountToken) {
            $wasPendingApproval = AccountTokenStatus::PendingApproval === $token->status();
            $token->revoke();
            $this->entityManager->flush();

            if ($wasPendingApproval) {
                $this->linkDelivery->notify($token, AccountMailFlow::RegistrationRejected, locale: $this->mailLocaleResolver->forAdminAction($token->user()));
            }

            $this->audit('user.account_token_revoked', ['email' => $token->email(), 'token_uid' => $token->uid()]);
            $this->addFlash('success', 'admin.users.invitation.revoked');
        }

        return $this->redirectAfterTokenAction($request);
    }

    private function redirectAfterTokenAction(Request $request): Response
    {
        return 'reviews' === $this->field($request, 'return_to')
            ? $this->redirectToRoute('backend_admin_user_reviews')
            : $this->redirectToRoute('backend_admin_users');
    }

    private function userByEmail(string $email): ?UserAccount
    {
        $user = $this->entityManager->getRepository(UserAccount::class)->findOneBy(['email' => strtolower($email)]);

        return $user instanceof UserAccount ? $user : null;
    }

    private function ttlForToken(AccountToken $token): string
    {
        return AccountTokenType::PasswordReset === $token->type()
            ? UserFlowConfig::PASSWORD_RESET_TTL
            : $this->userFlowConfig->accountLinkTtl();
    }

    private function flowForToken(AccountToken $token): AccountMailFlow
    {
        return match ($token->type()) {
            AccountTokenType::Invitation => AccountMailFlow::InvitationLink,
            AccountTokenType::Registration => AccountMailFlow::RegistrationLink,
            AccountTokenType::PasswordReset => AccountMailFlow::PasswordResetLink,
            AccountTokenType::SecurityReview => AccountMailFlow::PasswordChanged,
        };
    }

    private function urlForToken(AccountToken $token, string $plainToken): string
    {
        return match ($token->type()) {
            AccountTokenType::PasswordReset => $this->generateUrl('user_password_reset_token', ['token' => $plainToken], 0),
            AccountTokenType::SecurityReview => $this->generateUrl('user_security_review', ['token' => $plainToken], 0),
            default => $this->generateUrl('user_invitation_accept', ['token' => $plainToken], 0),
        };
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    private function groupIdentifiers(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value),
            static fn (string $item): bool => '' !== $item,
        ));
    }

    private function adminAccessResponse(Request $request): ?Response
    {
        $decision = $this->accessGuard->decide(BackendArea::Admin, $this->getUser());

        if ($decision->isGranted()) {
            return null;
        }

        return $this->httpError->render(Response::HTTP_UNAUTHORIZED, $request, context: [
            'area' => BackendArea::Admin->value,
            'access_decision' => $decision->toArray(),
        ]);
    }

    private function actor(): AccessActor
    {
        $user = $this->getUser();

        return $user instanceof UserAccount ? AccessActor::fromUserAccount($user) : AccessActor::anonymous();
    }

    private function field(Request $request, string $name): string
    {
        $value = $request->request->get($name);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function audit(string $action, array $context): void
    {
        try {
            $this->auditLogger->log($this->actor(), $action, $context);
        } catch (Throwable) {
            return;
        }
    }
}
