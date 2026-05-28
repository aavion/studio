<?php

declare(strict_types=1);

namespace App\Controller;

use App\Backend\BackendAccessGuard;
use App\Backend\BackendArea;
use App\Core\Access\AccessActor;
use App\Core\Access\AccessLevel;
use App\Core\Log\AuditLoggerInterface;
use App\Entity\AccountToken;
use App\Entity\AclGroup;
use App\Entity\UserAccount;
use App\Mail\AccountMailFlow;
use App\Mail\MailLocaleResolver;
use App\Navigation\NavigationBuilder;
use App\Security\AccountLinkDeliveryInterface;
use App\Security\AccountTokenIssuer;
use App\Security\AccountTokenStatus;
use App\Security\AccountTokenType;
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

final class AdminUserController extends AbstractController
{
    public function __construct(
        private readonly BackendAccessGuard $accessGuard,
        private readonly HttpErrorRenderer $httpError,
        private readonly NavigationBuilder $navigationBuilder,
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountTokenIssuer $tokenIssuer,
        private readonly AccountLinkDeliveryInterface $linkDelivery,
        private readonly MailLocaleResolver $mailLocaleResolver,
        private readonly UserFlowConfig $userFlowConfig,
        private readonly UserAccountLifecycle $userLifecycle,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('/admin/users', name: 'backend_admin_users', priority: 10, methods: ['GET'])]
    public function users(Request $request): Response
    {
        if ($response = $this->adminAccessResponse($request)) {
            return $response;
        }

        return $this->render('@backend/admin/users/index.html.twig', [
            'navigation' => $this->navigation($request),
            'users' => $this->entityManager->getRepository(UserAccount::class)->findBy([], ['username' => 'ASC']),
            'groups' => $this->entityManager->getRepository(AclGroup::class)->findBy([], ['accessLevel' => 'ASC', 'identifier' => 'ASC']),
            'pending_tokens' => $this->entityManager->getRepository(AccountToken::class)->findBy(
                ['status' => [AccountTokenStatus::Pending, AccountTokenStatus::PendingApproval]],
                ['createdAt' => 'DESC'],
            ),
        ]);
    }

    #[Route('/admin/users/reviews', name: 'backend_admin_user_reviews', priority: 10, methods: ['GET'])]
    public function reviews(Request $request): Response
    {
        if ($response = $this->adminAccessResponse($request)) {
            return $response;
        }

        $filter = $this->reviewFilter($request);
        $items = $this->reviewItems();

        if ('all' !== $filter) {
            $items = array_values(array_filter(
                $items,
                static fn (array $item): bool => $filter === $item['filter'] || ('expired' === $filter && true === $item['expired']),
            ));
        }

        return $this->render('@backend/admin/users/reviews.html.twig', [
            'navigation' => $this->navigation($request),
            'review_items' => $items,
            'review_filter' => $filter,
            'review_filters' => ['all', 'registrations', 'invitations', 'disputes', 'expired'],
        ]);
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
            if ($this->emailBelongsToUser($email)) {
                $this->addFlash('error', 'admin.users.form.errors.email_in_use');

                return $this->redirectToRoute('backend_admin_users');
            }

            $this->revokePendingTokensForEmail($email, [AccountTokenType::Invitation, AccountTokenType::Registration]);
            [$token, $plainToken] = $this->tokenIssuer->issue(AccountTokenType::Invitation, $email, $groups, ttl: $this->userFlowConfig->accountLinkTtl());
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
    public function approveInvitation(Request $request, string $uid): Response
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
    public function reissueInvitation(Request $request, string $uid): Response
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
    public function revokeInvitation(Request $request, string $uid): Response
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

    #[Route('/admin/users/reviews/{uid}/reactivate', name: 'backend_admin_user_review_reactivate', requirements: ['uid' => '[a-f0-9-]{36}'], priority: 10, methods: ['POST'])]
    public function reactivateReviewUser(Request $request, string $uid): Response
    {
        if ($response = $this->adminAccessResponse($request)) {
            return $response;
        }

        $user = $this->entityManager->find(UserAccount::class, $uid);

        if (!$user instanceof UserAccount) {
            return $this->httpError->notFound($request);
        }

        if (!$this->isCsrfTokenValid('admin_user_review_'.$uid, $this->field($request, '_csrf_token'))) {
            $this->addFlash('error', 'admin.users.form.errors.invalid_csrf');

            return $this->redirectToRoute('backend_admin_user_reviews');
        }

        $user->changePassword($this->passwordHasher->hashPassword($user, bin2hex(random_bytes(32))));
        $this->userLifecycle->changeStatus($user, UserAccountStatus::Active);
        $this->entityManager->flush();
        $this->linkDelivery->notifyAddress($user->email(), AccountMailFlow::PasswordChangeReactivated, $this->mailLocaleResolver->forAdminAction($user), [
            'username' => $user->username(),
            'user_uid' => $user->uid(),
        ]);
        $this->audit('user.security_review_reactivated', ['target_user' => $user->uid()]);
        $this->addFlash('success', 'admin.user_reviews.actions.reactivated');

        return $this->redirectToRoute('backend_admin_user_reviews');
    }

    #[Route('/admin/users/reviews/{uid}/delete', name: 'backend_admin_user_review_delete', requirements: ['uid' => '[a-f0-9-]{36}'], priority: 10, methods: ['POST'])]
    public function deleteReviewUser(Request $request, string $uid): Response
    {
        if ($response = $this->adminAccessResponse($request)) {
            return $response;
        }

        $user = $this->entityManager->find(UserAccount::class, $uid);

        if (!$user instanceof UserAccount) {
            return $this->httpError->notFound($request);
        }

        if (!$this->isCsrfTokenValid('admin_user_review_'.$uid, $this->field($request, '_csrf_token'))) {
            $this->addFlash('error', 'admin.users.form.errors.invalid_csrf');

            return $this->redirectToRoute('backend_admin_user_reviews');
        }

        if ('1' !== $this->field($request, 'confirm_delete')) {
            $this->addFlash('error', 'admin.user_reviews.actions.delete_confirmation_required');

            return $this->redirectToRoute('backend_admin_user_reviews');
        }

        $effects = $this->userLifecycle->changeStatus($user, UserAccountStatus::Deleted);
        $this->entityManager->flush();
        $this->audit('user.security_review_deleted', ['target_user' => $user->uid(), ...$effects]);
        $this->addFlash('success', 'admin.user_reviews.actions.deleted');

        return $this->redirectToRoute('backend_admin_user_reviews');
    }

    #[Route('/admin/users/{uid}', name: 'backend_admin_user_detail', requirements: ['uid' => '[a-f0-9-]{36}'], priority: 10, methods: ['GET', 'POST'])]
    public function user(Request $request, string $uid): Response
    {
        if ($response = $this->adminAccessResponse($request)) {
            return $response;
        }

        $user = $this->entityManager->find(UserAccount::class, $uid);

        if (!$user instanceof UserAccount) {
            return $this->httpError->notFound($request);
        }

        if ($request->isMethod('POST')) {
            $this->updateUser($request, $user);

            return $this->redirectToRoute('backend_admin_user_detail', ['uid' => $uid]);
        }

        return $this->render('@backend/admin/users/detail.html.twig', [
            'navigation' => $this->navigation($request),
            'user_account' => $user,
            'groups' => $this->entityManager->getRepository(AclGroup::class)->findBy([], ['accessLevel' => 'ASC', 'identifier' => 'ASC']),
        ]);
    }

    #[Route('/admin/users/{uid}/password-reset', name: 'backend_admin_user_password_reset', requirements: ['uid' => '[a-f0-9-]{36}'], priority: 10, methods: ['POST'])]
    public function passwordReset(Request $request, string $uid): Response
    {
        if ($response = $this->adminAccessResponse($request)) {
            return $response;
        }

        $user = $this->entityManager->find(UserAccount::class, $uid);

        if (!$user instanceof UserAccount) {
            return $this->httpError->notFound($request);
        }

        if (!$this->isCsrfTokenValid('admin_user_password_reset_'.$uid, $this->field($request, '_csrf_token'))) {
            $this->addFlash('error', 'admin.users.form.errors.invalid_csrf');

            return $this->redirectToRoute('backend_admin_user_detail', ['uid' => $uid]);
        }

        $this->revokePendingTokensForUser($user, [AccountTokenType::PasswordReset]);
        [$token, $plainToken] = $this->tokenIssuer->issue(AccountTokenType::PasswordReset, $user->email(), [], $user, ttl: UserFlowConfig::PASSWORD_RESET_TTL);
        $this->entityManager->persist($token);
        $this->entityManager->flush();
        $this->linkDelivery->deliver($token, AccountMailFlow::PasswordResetLink, $plainToken, $this->generateUrl('user_password_reset_token', ['token' => $plainToken], 0), $this->mailLocaleResolver->forAdminAction($user));
        $this->audit('user.password_reset_created', ['target_user' => $user->uid(), 'token_uid' => $token->uid()]);
        $this->addFlash('success', 'admin.users.password_reset.created');

        return $this->redirectToRoute('backend_admin_user_detail', ['uid' => $uid]);
    }

    #[Route('/admin/users/groups', name: 'backend_admin_user_groups', priority: 10, methods: ['GET', 'POST'])]
    public function groups(Request $request): Response
    {
        if ($response = $this->adminAccessResponse($request)) {
            return $response;
        }

        if ($request->isMethod('POST')) {
            $this->createGroup($request);

            return $this->redirectToRoute('backend_admin_user_groups');
        }

        return $this->render('@backend/admin/users/groups.html.twig', [
            'navigation' => $this->navigation($request),
            'groups' => $this->entityManager->getRepository(AclGroup::class)->findBy([], ['accessLevel' => 'ASC', 'identifier' => 'ASC']),
        ]);
    }

    #[Route('/admin/users/groups/{uid}', name: 'backend_admin_user_group_detail', requirements: ['uid' => '[a-f0-9-]{36}'], priority: 10, methods: ['GET', 'POST'])]
    public function group(Request $request, string $uid): Response
    {
        if ($response = $this->adminAccessResponse($request)) {
            return $response;
        }

        $group = $this->entityManager->find(AclGroup::class, $uid);

        if (!$group instanceof AclGroup) {
            return $this->httpError->notFound($request);
        }

        if ($request->isMethod('POST')) {
            $this->updateGroup($request, $group);

            return $this->redirectToRoute('backend_admin_user_group_detail', ['uid' => $uid]);
        }

        return $this->render('@backend/admin/users/group-detail.html.twig', [
            'navigation' => $this->navigation($request),
            'group' => $group,
            'member_count' => $this->memberCount($group),
        ]);
    }

    #[Route('/admin/users/groups/{uid}/delete', name: 'backend_admin_user_group_delete', requirements: ['uid' => '[a-f0-9-]{36}'], priority: 10, methods: ['POST'])]
    public function deleteGroup(Request $request, string $uid): Response
    {
        if ($response = $this->adminAccessResponse($request)) {
            return $response;
        }

        $group = $this->entityManager->find(AclGroup::class, $uid);

        if (!$group instanceof AclGroup) {
            return $this->httpError->notFound($request);
        }

        if (!$this->isCsrfTokenValid('admin_group_delete_'.$uid, $this->field($request, '_csrf_token'))) {
            $this->addFlash('error', 'admin.users.form.errors.invalid_csrf');

            return $this->redirectToRoute('backend_admin_user_group_detail', ['uid' => $uid]);
        }

        if ($group->isLocked() || (!$group->allowsEmptyMembership() && 0 < $this->memberCount($group))) {
            $this->addFlash('error', 'admin.groups.delete_blocked');

            return $this->redirectToRoute('backend_admin_user_group_detail', ['uid' => $uid]);
        }

        $this->entityManager->remove($group);
        $this->entityManager->flush();
        $this->audit('acl.group_deleted', ['group' => $group->identifier()]);
        $this->addFlash('success', 'admin.groups.deleted');

        return $this->redirectToRoute('backend_admin_user_groups');
    }

    private function updateUser(Request $request, UserAccount $user): void
    {
        if (!$this->isCsrfTokenValid('admin_user_'.$user->uid(), $this->field($request, '_csrf_token'))) {
            $this->addFlash('error', 'admin.users.form.errors.invalid_csrf');

            return;
        }

        $status = UserAccountStatus::tryFrom($this->field($request, 'status'));

        if (!$status instanceof UserAccountStatus) {
            $this->addFlash('error', 'admin.users.form.errors.invalid_status');

            return;
        }

        $effects = $this->userLifecycle->changeStatus($user, $status);
        $this->syncGroups($user, $this->groupIdentifiers($request->request->all('groups')));
        $this->entityManager->flush();
        $this->audit('user.account_updated', ['target_user' => $user->uid(), 'status' => $status->value, ...$effects]);
        $this->addFlash('success', 'admin.users.saved');
    }

    private function createGroup(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_group_create', $this->field($request, '_csrf_token'))) {
            $this->addFlash('error', 'admin.users.form.errors.invalid_csrf');

            return;
        }

        try {
            $group = new AclGroup(
                self::uuid(),
                $this->field($request, 'identifier'),
                [
                    'en' => $this->field($request, 'name_en'),
                    'de' => $this->field($request, 'name_de') ?: $this->field($request, 'name_en'),
                ],
                (int) $this->field($request, 'access_level'),
                false,
                true,
            );
            $this->entityManager->persist($group);
            $this->entityManager->flush();
            $this->audit('acl.group_created', ['group' => $group->identifier()]);
            $this->addFlash('success', 'admin.groups.created');
        } catch (Throwable) {
            $this->addFlash('error', 'admin.groups.form.invalid');
        }
    }

    private function updateGroup(Request $request, AclGroup $group): void
    {
        if (!$this->isCsrfTokenValid('admin_group_'.$group->uid(), $this->field($request, '_csrf_token'))) {
            $this->addFlash('error', 'admin.users.form.errors.invalid_csrf');

            return;
        }

        if ($group->isLocked()) {
            $this->addFlash('error', 'admin.groups.locked');

            return;
        }

        try {
            $group->rename([
                'en' => $this->field($request, 'name_en'),
                'de' => $this->field($request, 'name_de') ?: $this->field($request, 'name_en'),
            ]);
            $group->changeAccessLevel((int) $this->field($request, 'access_level'));
            $group->changeEmptyMembershipPolicy('1' === $this->field($request, 'allow_empty'));
            $this->entityManager->flush();
            $this->audit('acl.group_updated', ['group' => $group->identifier()]);
            $this->addFlash('success', 'admin.groups.saved');
        } catch (Throwable) {
            $this->addFlash('error', 'admin.groups.form.invalid');
        }
    }

    /**
     * @param list<string> $groupIdentifiers
     */
    private function syncGroups(UserAccount $user, array $groupIdentifiers): void
    {
        $groups = $this->entityManager->getRepository(AclGroup::class)->findBy(['identifier' => $groupIdentifiers]);
        $wanted = [];

        foreach ($groups as $group) {
            $wanted[$group->uid()] = $group;
        }

        foreach ($user->groups()->toArray() as $group) {
            if ($group instanceof AclGroup && !isset($wanted[$group->uid()])) {
                $user->removeGroup($group);
            }
        }

        foreach ($wanted as $group) {
            $user->addGroup($group);
        }
    }

    private function memberCount(AclGroup $group): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM user_acl_group WHERE group_uid = ?',
            [$group->uid()],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reviewItems(): array
    {
        $items = [];
        $tokens = $this->entityManager->getRepository(AccountToken::class)->findBy(
            [
                'type' => [AccountTokenType::Invitation, AccountTokenType::Registration, AccountTokenType::SecurityReview],
                'status' => [AccountTokenStatus::Pending, AccountTokenStatus::PendingApproval, AccountTokenStatus::Used],
            ],
            ['createdAt' => 'DESC'],
        );

        foreach ($tokens as $token) {
            if (!$token instanceof AccountToken) {
                continue;
            }

            $item = match ($token->type()) {
                AccountTokenType::Invitation, AccountTokenType::Registration => $this->accountLinkReviewItem($token),
                AccountTokenType::SecurityReview => $this->securityReviewItem($token),
                AccountTokenType::PasswordReset => null,
            };

            if (null !== $item) {
                $items[] = $item;
            }
        }

        usort($items, static fn (array $left, array $right): int => $right['requested_at']->getTimestamp() <=> $left['requested_at']->getTimestamp());

        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function accountLinkReviewItem(AccountToken $token): ?array
    {
        if (!in_array($token->status(), [AccountTokenStatus::Pending, AccountTokenStatus::PendingApproval], true)) {
            return null;
        }

        $expired = AccountTokenStatus::Pending === $token->status() && $token->isExpired();
        $approval = AccountTokenStatus::PendingApproval === $token->status();
        $type = $token->type();

        return [
            'kind' => $approval ? 'registration_approval' : $type->value,
            'filter' => $type === AccountTokenType::Invitation ? 'invitations' : 'registrations',
            'status' => $approval ? 'pending_approval' : ($expired ? 'expired' : 'open'),
            'expired' => $expired,
            'token' => $token,
            'user' => null,
            'email' => $token->email(),
            'username' => null,
            'requested_at' => $token->createdAt(),
            'groups' => $token->groupIdentifiers(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function securityReviewItem(AccountToken $token): ?array
    {
        $user = $token->user();

        if (AccountTokenStatus::Used !== $token->status() || !$user instanceof UserAccount || UserAccountStatus::Inactive !== $user->status()) {
            return null;
        }

        return [
            'kind' => 'password_dispute',
            'filter' => 'disputes',
            'status' => 'locked',
            'expired' => false,
            'token' => $token,
            'user' => $user,
            'email' => $user->email(),
            'username' => $user->username(),
            'requested_at' => $token->consumedAt() ?? $token->createdAt(),
            'groups' => [],
        ];
    }

    private function reviewFilter(Request $request): string
    {
        $filter = $request->query->get('filter');

        return is_string($filter) && in_array($filter, ['all', 'registrations', 'invitations', 'disputes', 'expired'], true)
            ? $filter
            : 'all';
    }

    private function redirectAfterTokenAction(Request $request): Response
    {
        return 'reviews' === $this->field($request, 'return_to')
            ? $this->redirectToRoute('backend_admin_user_reviews')
            : $this->redirectToRoute('backend_admin_users');
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

    private function emailBelongsToUser(string $email): bool
    {
        return $this->entityManager->getRepository(UserAccount::class)->findOneBy(['email' => strtolower($email)]) instanceof UserAccount;
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

    /**
     * @return list<array<string, mixed>>
     */
    private function navigation(Request $request): array
    {
        return $this->navigationBuilder->build(
            BackendArea::Admin->navigationIdentifier(),
            (string) $request->getLocale(),
            actor: $this->actor(),
            activeUrl: $request->getPathInfo(),
            activeRoute: (string) $request->attributes->get('_route'),
        );
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

    private function audit(string $action, array $context): void
    {
        try {
            $this->auditLogger->log($this->actor(), $action, $context);
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
