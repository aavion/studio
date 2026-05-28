<?php

declare(strict_types=1);

namespace App\Controller;

use App\Backend\AdminControllerContext;
use App\Core\Routing\AbsoluteUriGenerator;
use App\Core\State\StateMarkerKey;
use App\Core\State\StateMarkerRecorder;
use App\Core\State\StateSubjectType;
use App\Entity\AccountToken;
use App\Entity\AclGroup;
use App\Entity\UserAccount;
use App\Mail\AccountMailFlow;
use App\Mail\MailLocaleResolver;
use App\Security\AccountLinkDeliveryInterface;
use App\Security\AccountTokenIssuer;
use App\Security\AccountTokenMaintenance;
use App\Security\AccountTokenStatus;
use App\Security\AccountTokenType;
use App\Security\AdminUserAccessPolicy;
use App\Security\AdminUserListViewFactory;
use App\Security\DeletedUserCleanup;
use App\Security\UserAccountLifecycle;
use App\Security\UserAccountStatus;
use App\Security\UserFlowConfig;
use App\View\Http\HttpErrorRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminUserController extends AbstractController
{
    public function __construct(
        private readonly AdminControllerContext $adminContext,
        private readonly HttpErrorRenderer $httpError,
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountTokenIssuer $tokenIssuer,
        private readonly AccountTokenMaintenance $tokenMaintenance,
        private readonly AccountLinkDeliveryInterface $linkDelivery,
        private readonly AbsoluteUriGenerator $absoluteUris,
        private readonly MailLocaleResolver $mailLocaleResolver,
        private readonly UserAccountLifecycle $userLifecycle,
        private readonly AdminUserAccessPolicy $adminUserPolicy,
        private readonly AdminUserListViewFactory $adminUserLists,
        private readonly StateMarkerRecorder $stateMarkers,
        private readonly DeletedUserCleanup $deletedUserCleanup,
        private readonly UserFlowConfig $userFlowConfig,
    ) {
    }

    #[Route('/admin/users', name: 'backend_admin_users', priority: 10, methods: ['GET'])]
    public function users(Request $request): Response
    {
        if ($response = $this->adminContext->accessResponse($request, $this->getUser())) {
            return $response;
        }

        $usersView = $this->adminUserLists->usersView($request);

        return $this->render('@backend/admin/users/index.html.twig', [
            'navigation' => $this->adminContext->navigation($request, $this->getUser()),
            'users' => $usersView['items'],
            'users_view' => $usersView,
            'groups' => $this->assignableGroups(),
            'pending_tokens' => $this->entityManager->getRepository(AccountToken::class)->findBy(
                ['status' => [AccountTokenStatus::Pending, AccountTokenStatus::PendingApproval]],
                ['createdAt' => 'DESC'],
            ),
        ]);
    }

    #[Route('/admin/users/deleted', name: 'backend_admin_deleted_users', priority: 10, methods: ['GET'])]
    public function deletedUsers(Request $request): Response
    {
        if ($response = $this->adminContext->accessResponse($request, $this->getUser())) {
            return $response;
        }

        return $this->render('@backend/admin/users/deleted.html.twig', [
            'navigation' => $this->adminContext->navigation($request, $this->getUser()),
            'deleted_users' => $this->deletedUserCleanup->deletedUsers(),
            'retention_days' => $this->deletedUserCleanup->retentionDays(),
            'cleanup_cutoff' => $this->deletedUserCleanup->cutoff(),
        ]);
    }

    #[Route('/admin/users/deleted/cleanup', name: 'backend_admin_deleted_users_cleanup', priority: 10, methods: ['POST'])]
    public function cleanupDeletedUsers(Request $request): Response
    {
        if ($response = $this->adminContext->accessResponse($request, $this->getUser())) {
            return $response;
        }

        if (!$this->isCsrfTokenValid('admin_deleted_users_cleanup', $this->field($request, '_csrf_token'))) {
            $this->addFlash('error', 'admin.users.form.errors.invalid_csrf');

            return $this->redirectToRoute('backend_admin_deleted_users');
        }

        $result = $this->deletedUserCleanup->cleanupExpired();
        $this->adminContext->audit($this->getUser(), 'user.deleted_cleanup', [
            'removed' => $result['removed'],
            'retention_days' => $result['retention_days'],
            'cutoff' => $result['cutoff']->format(DATE_ATOM),
            'user_uids' => $result['user_uids'],
        ]);
        $this->addFlash('success', 'admin.users.deleted.cleanup_done');

        return $this->redirectToRoute('backend_admin_deleted_users');
    }

    #[Route('/admin/users/deleted/{uid}/activate', name: 'backend_admin_deleted_user_activate', requirements: ['uid' => '[a-f0-9-]{36}'], priority: 10, methods: ['POST'])]
    public function activateDeletedUser(Request $request, string $uid): Response
    {
        return $this->changeDeletedUserStatus($request, $uid, UserAccountStatus::Active);
    }

    #[Route('/admin/users/deleted/{uid}/deactivate', name: 'backend_admin_deleted_user_deactivate', requirements: ['uid' => '[a-f0-9-]{36}'], priority: 10, methods: ['POST'])]
    public function deactivateDeletedUser(Request $request, string $uid): Response
    {
        return $this->changeDeletedUserStatus($request, $uid, UserAccountStatus::Inactive);
    }

    #[Route('/admin/users/{uid}', name: 'backend_admin_user_detail', requirements: ['uid' => '[a-f0-9-]{36}'], priority: 10, methods: ['GET', 'POST'])]
    public function user(Request $request, string $uid): Response
    {
        if ($response = $this->adminContext->accessResponse($request, $this->getUser())) {
            return $response;
        }

        $user = $this->entityManager->find(UserAccount::class, $uid);

        if (!$user instanceof UserAccount || DeletedUserCleanup::DELETED_USER_UID === $user->uid()) {
            return $this->httpError->notFound($request);
        }

        if ($request->isMethod('POST')) {
            $this->updateUser($request, $user);

            return $this->redirectToRoute('backend_admin_user_detail', ['uid' => $uid]);
        }

        return $this->render('@backend/admin/users/detail.html.twig', [
            'navigation' => $this->adminContext->navigation($request, $this->getUser()),
            'user_account' => $user,
            'groups' => $this->assignableGroups(),
            'login_possible' => $user->status()->isUsable(),
            'state_history' => $this->stateMarkers->history(StateSubjectType::USER_ACCOUNT, $user->uid()),
            'audit_log_url' => $this->generateUrl('backend_admin_route', [
                'path' => 'logs',
                'source' => 'audit',
                'q' => $user->uid(),
                'match' => 'contains',
                'time_window' => '30d',
            ]),
        ]);
    }

    #[Route('/admin/users/{uid}/password-reset', name: 'backend_admin_user_password_reset', requirements: ['uid' => '[a-f0-9-]{36}'], priority: 10, methods: ['POST'])]
    public function passwordReset(Request $request, string $uid): Response
    {
        if ($response = $this->adminContext->accessResponse($request, $this->getUser())) {
            return $response;
        }

        $user = $this->entityManager->find(UserAccount::class, $uid);

        if (!$user instanceof UserAccount || DeletedUserCleanup::DELETED_USER_UID === $user->uid()) {
            return $this->httpError->notFound($request);
        }

        if (!$this->isCsrfTokenValid('admin_user_password_reset_'.$uid, $this->field($request, '_csrf_token'))) {
            $this->addFlash('error', 'admin.users.form.errors.invalid_csrf');

            return $this->redirectToRoute('backend_admin_user_detail', ['uid' => $uid]);
        }

        if ($error = $this->adminUserPolicy->validateUserAction($this->adminContext->actor($this->getUser()), $user)) {
            $this->addFlash('error', $error);

            return $this->redirectToRoute('backend_admin_user_detail', ['uid' => $uid]);
        }

        $this->tokenMaintenance->revokePendingForUser($user, [AccountTokenType::PasswordReset]);
        [$token, $plainToken] = $this->tokenIssuer->issue(AccountTokenType::PasswordReset, $user->email(), [], $user, ttl: UserFlowConfig::PASSWORD_RESET_TTL);
        $url = $this->absoluteUris->generateUri(__METHOD__, 'user_password_reset_token', ['token' => $plainToken]);

        if (null === $url) {
            $this->addFlash('error', 'admin.users.form.errors.mail_delivery_failed');

            return $this->redirectToRoute('backend_admin_user_detail', ['uid' => $uid]);
        }

        $this->entityManager->persist($token);
        $this->entityManager->flush();
        $this->linkDelivery->deliver($token, AccountMailFlow::PasswordResetLink, $plainToken, $url, $this->mailLocaleResolver->forAdminAction($user));
        $this->adminContext->audit($this->getUser(), 'user.password_reset_created', ['target_user' => $user->uid(), 'token_uid' => $token->uid()]);
        $this->addFlash('success', 'admin.users.password_reset.created');

        return $this->redirectToRoute('backend_admin_user_detail', ['uid' => $uid]);
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

        $newGroupIdentifiers = $this->groupIdentifiers($request->request->all('groups'));

        if ($error = $this->adminUserPolicy->validateUserUpdate($this->adminContext->actor($this->getUser()), $user, $status, $newGroupIdentifiers)) {
            $this->addFlash('error', $error);

            return;
        }

        $oldStatus = $user->status()->value;
        $oldGroups = $this->userGroupIdentifiers($user);
        $oldAccessLevel = $user->maxAccessLevel();
        $effects = $this->userLifecycle->changeStatus($user, $status, $this->adminContext->actorName($this->getUser()));
        $this->syncGroups($user, $newGroupIdentifiers);
        $this->stateMarkers->record(StateSubjectType::USER_ACCOUNT, $user->uid(), StateMarkerKey::MODIFIED, $this->adminContext->actorName($this->getUser()), 'admin_update', [
            'old_groups' => $oldGroups,
            'new_groups' => $newGroupIdentifiers,
        ]);
        $this->entityManager->flush();
        $this->adminContext->audit($this->getUser(), 'user.account_updated', [
            'target_user' => $user->uid(),
            'old_status' => $oldStatus,
            'new_status' => $status->value,
            'old_groups' => $oldGroups,
            'new_groups' => $this->userGroupIdentifiers($user),
            'old_access_level' => $oldAccessLevel,
            'new_access_level' => $user->maxAccessLevel(),
            ...$effects,
        ]);
        $this->addFlash('success', 'admin.users.saved');
    }

    private function changeDeletedUserStatus(Request $request, string $uid, UserAccountStatus $status): Response
    {
        if ($response = $this->adminContext->accessResponse($request, $this->getUser())) {
            return $response;
        }

        $user = $this->entityManager->find(UserAccount::class, $uid);

        if (!$user instanceof UserAccount || DeletedUserCleanup::DELETED_USER_UID === $user->uid()) {
            return $this->httpError->notFound($request);
        }

        if (!$this->isCsrfTokenValid('admin_deleted_user_status_'.$uid, $this->field($request, '_csrf_token'))) {
            $this->addFlash('error', 'admin.users.form.errors.invalid_csrf');

            return $this->redirectToRoute('backend_admin_deleted_users');
        }

        if (UserAccountStatus::Deleted !== $user->status()) {
            $this->addFlash('error', 'admin.users.deleted.not_deleted');

            return $this->redirectToRoute('backend_admin_deleted_users');
        }

        $groups = $this->userGroupIdentifiers($user);
        $heal = '1' === $this->field($request, 'heal_groups');
        $defaultGroupIdentifier = $this->userFlowConfig->defaultAclGroupIdentifier();

        if ($heal) {
            $groups = [$defaultGroupIdentifier];
        }

        $error = $this->adminUserPolicy->validateUserUpdate($this->adminContext->actor($this->getUser()), $user, $status, $groups);

        if (in_array($error, ['admin.users.form.errors.group_required', 'admin.users.form.errors.group_access_too_low'], true) && !$heal) {
            return $this->render('@backend/admin/users/status-heal.html.twig', [
                'navigation' => $this->adminContext->navigation($request, $this->getUser()),
                'user_account' => $user,
                'target_status' => $status,
                'error' => $error,
                'default_group_identifier' => $defaultGroupIdentifier,
            ]);
        }

        if (null !== $error) {
            $this->addFlash('error', $error);

            return $this->redirectToRoute('backend_admin_deleted_users');
        }

        $oldStatus = $user->status()->value;
        $oldGroups = $this->userGroupIdentifiers($user);
        $effects = $this->userLifecycle->changeStatus($user, $status, $this->adminContext->actorName($this->getUser()));

        if ($heal) {
            $this->syncGroups($user, $groups);
        }

        $this->entityManager->flush();

        if (UserAccountStatus::Active === $status) {
            $this->linkDelivery->notifyAddress($user->email(), AccountMailFlow::AccountRestored, $this->mailLocaleResolver->forAdminAction($user), [
                'username' => $user->username(),
                'user_uid' => $user->uid(),
            ]);
        }

        $this->adminContext->audit($this->getUser(), UserAccountStatus::Active === $status ? 'user.deleted_account_activated' : 'user.deleted_account_deactivated', [
            'target_user' => $user->uid(),
            'old_status' => $oldStatus,
            'new_status' => $status->value,
            'old_groups' => $oldGroups,
            'new_groups' => $this->userGroupIdentifiers($user),
            'groups_healed' => $heal,
            ...$effects,
        ]);
        $this->addFlash('success', UserAccountStatus::Active === $status ? 'admin.users.deleted.activated' : 'admin.users.deleted.deactivated');

        return $this->redirectToRoute('backend_admin_deleted_users');
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

    /**
     * @return list<AclGroup>
     */
    private function assignableGroups(): array
    {
        return array_values(array_filter(
            $this->entityManager->getRepository(AclGroup::class)->findBy([], ['accessLevel' => 'ASC', 'identifier' => 'ASC']),
            fn (mixed $group): bool => $group instanceof AclGroup && $this->adminUserPolicy->canAssignGroup($this->adminContext->actor($this->getUser()), $group),
        ));
    }

    /**
     * @return list<string>
     */
    private function userGroupIdentifiers(UserAccount $user): array
    {
        $identifiers = [];

        foreach ($user->groups() as $group) {
            if ($group instanceof AclGroup) {
                $identifiers[] = $group->identifier();
            }
        }

        sort($identifiers);

        return $identifiers;
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

    private function field(Request $request, string $name): string
    {
        $value = $request->request->get($name);

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
