<?php

declare(strict_types=1);

namespace App\Controller;

use App\Backend\AdminControllerContext;
use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\State\StateMarkerRecorder;
use App\Core\State\StateSubjectType;
use App\Entity\AccountToken;
use App\Entity\UserAccount;
use App\Security\AccountTokenStatus;
use App\Security\AdminUserAccountUpdateService;
use App\Security\AdminUserAssignmentOptions;
use App\Security\AdminUserListViewFactory;
use App\Security\AdminUserPasswordResetService;
use App\Security\DeletedUserCleanup;
use App\Security\UserAccountStatus;
use App\Security\UserRole;
use App\View\Alert\UiAlertDelivery;
use App\View\Alert\UiAlertDispatcherInterface;
use App\View\Alert\UiAlertTranslation;
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
        private readonly AdminUserAccountUpdateService $accountUpdateService,
        private readonly AdminUserAssignmentOptions $assignmentOptions,
        private readonly AdminUserPasswordResetService $passwordResetService,
        private readonly AdminUserListViewFactory $adminUserLists,
        private readonly StateMarkerRecorder $stateMarkers,
        private readonly DeletedUserCleanup $deletedUserCleanup,
        private readonly UiAlertDispatcherInterface $alerts,
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
            'groups' => $this->assignmentOptions->groups($this->adminContext->actor($this->getUser()), UserRole::User),
            'invite_groups_by_role' => $this->assignmentOptions->groupOptionsByRole($this->adminContext->actor($this->getUser())),
            'role_options' => $this->assignmentOptions->roles($this->adminContext->actor($this->getUser())),
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
            $this->alertKey('error', 'admin.users.form.errors.invalid_csrf');

            return $this->redirectToRoute('backend_admin_deleted_users');
        }

        $result = $this->deletedUserCleanup->cleanupExpired();
        $this->adminContext->audit($this->getUser(), 'user.deleted_cleanup', [
            'removed' => $result['removed'],
            'retention_days' => $result['retention_days'],
            'cutoff' => $result['cutoff']->format(DATE_ATOM),
            'user_uids' => $result['user_uids'],
        ]);
        $this->alertKey('success', 'admin.users.deleted.cleanup_done');

        return $this->redirectToRoute('backend_admin_deleted_users');
    }

    #[Route('/admin/users/deleted/details/{username}/activate', name: 'backend_admin_deleted_user_activate', requirements: ['username' => '[A-Za-z][A-Za-z0-9_-]{4,29}'], priority: 0, methods: ['POST'])]
    public function activateDeletedUser(Request $request, string $username): Response
    {
        return $this->changeDeletedUserStatus($request, $username, UserAccountStatus::Active);
    }

    #[Route('/admin/users/deleted/details/{username}/deactivate', name: 'backend_admin_deleted_user_deactivate', requirements: ['username' => '[A-Za-z][A-Za-z0-9_-]{4,29}'], priority: 0, methods: ['POST'])]
    public function deactivateDeletedUser(Request $request, string $username): Response
    {
        return $this->changeDeletedUserStatus($request, $username, UserAccountStatus::Inactive);
    }

    #[Route('/admin/users/details/{username}', name: 'backend_admin_user_detail', requirements: ['username' => '[A-Za-z][A-Za-z0-9_-]{4,29}'], priority: 0, methods: ['GET', 'POST'])]
    public function user(Request $request, string $username): Response
    {
        if ($response = $this->adminContext->accessResponse($request, $this->getUser())) {
            return $response;
        }

        $user = $this->userByUsername($username);

        if (
            !$user instanceof UserAccount
            || DeletedUserCleanup::DELETED_USER_UID === $user->uid()
            || UserAccountStatus::Deleted === $user->status()
        ) {
            return $this->httpError->notFound($request);
        }

        if ($request->isMethod('POST')) {
            $this->updateUser($request, $user);

            return $this->redirectToRoute('backend_admin_user_detail', ['username' => $user->username()]);
        }

        return $this->render('@backend/admin/users/detail.html.twig', [
            'navigation' => $this->adminContext->navigation($request, $this->getUser()),
            'user_account' => $user,
            'groups' => $this->assignmentOptions->groups($this->adminContext->actor($this->getUser()), $user->role()),
            'role_options' => $this->assignmentOptions->roles($this->adminContext->actor($this->getUser())),
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

    #[Route('/admin/users/details/{username}/password-reset', name: 'backend_admin_user_password_reset', requirements: ['username' => '[A-Za-z][A-Za-z0-9_-]{4,29}'], priority: 0, methods: ['POST'])]
    public function passwordReset(Request $request, string $username): Response
    {
        if ($response = $this->adminContext->accessResponse($request, $this->getUser())) {
            return $response;
        }

        $user = $this->userByUsername($username);

        if (
            !$user instanceof UserAccount
            || DeletedUserCleanup::DELETED_USER_UID === $user->uid()
            || UserAccountStatus::Deleted === $user->status()
        ) {
            return $this->httpError->notFound($request);
        }

        if (!$this->isCsrfTokenValid('admin_user_password_reset_'.$user->username(), $this->field($request, '_csrf_token'))) {
            $this->alertKey('error', 'admin.users.form.errors.invalid_csrf');

            return $this->redirectToRoute('backend_admin_user_detail', ['username' => $user->username()]);
        }

        $result = $this->passwordResetService->create($this->adminContext->actor($this->getUser()), $user);
        $this->alertKey($result->successLevel(), $result->flashKey());

        return $this->redirectToRoute('backend_admin_user_detail', ['username' => $user->username()]);
    }

    private function updateUser(Request $request, UserAccount $user): void
    {
        if (!$this->isCsrfTokenValid('admin_user_'.$user->username(), $this->field($request, '_csrf_token'))) {
            $this->alertKey('error', 'admin.users.form.errors.invalid_csrf');

            return;
        }

        $status = UserAccountStatus::tryFrom($this->field($request, 'status'));

        if (!$status instanceof UserAccountStatus) {
            $this->alertKey('error', 'admin.users.form.errors.invalid_status');

            return;
        }

        $newGroupIdentifiers = $this->groupIdentifiers($request->request->all('groups'));
        $role = UserRole::tryFrom($this->field($request, 'role'));

        if (!$role instanceof UserRole || UserRole::Public === $role) {
            $this->alertKey('error', 'admin.users.form.errors.invalid_role');

            return;
        }

        $result = $this->accountUpdateService->update(
            $this->adminContext->actor($this->getUser()),
            $this->adminContext->actorName($this->getUser()),
            $user,
            $status,
            $role,
            $newGroupIdentifiers,
        );
        $this->adminContext->audit($this->getUser(), $result->auditAction(), $result->auditContext());
        $this->alertKey($result->flashLevel(), $result->flashKey());
    }

    private function changeDeletedUserStatus(Request $request, string $username, UserAccountStatus $status): Response
    {
        if ($response = $this->adminContext->accessResponse($request, $this->getUser())) {
            return $response;
        }

        $user = $this->userByUsername($username);

        if (!$user instanceof UserAccount || DeletedUserCleanup::DELETED_USER_UID === $user->uid()) {
            return $this->httpError->notFound($request);
        }

        if (!$this->isCsrfTokenValid('admin_deleted_user_status_'.$user->username(), $this->field($request, '_csrf_token'))) {
            $this->alertKey('error', 'admin.users.form.errors.invalid_csrf');

            return $this->redirectToRoute('backend_admin_deleted_users');
        }

        if (UserAccountStatus::Deleted !== $user->status()) {
            $this->alertKey('error', 'admin.users.deleted.not_deleted');

            return $this->redirectToRoute('backend_admin_deleted_users');
        }

        $result = $this->accountUpdateService->changeDeletedStatus(
            $this->adminContext->actor($this->getUser()),
            $this->adminContext->actorName($this->getUser()),
            $user,
            $status,
        );
        $this->adminContext->audit($this->getUser(), $result->auditAction(), $result->auditContext());
        $this->alertKey($result->flashLevel(), $result->flashKey());

        return $this->redirectToRoute('backend_admin_deleted_users');
    }

    private function userByUsername(string $username): ?UserAccount
    {
        $user = $this->entityManager->getRepository(UserAccount::class)->findOneBy(['username' => $username]);

        return $user instanceof UserAccount ? $user : null;
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

    private function alertKey(string $level, string $key): void
    {
        $this->alerts->addAlert(UiAlertTranslation::forLevel($level, $key), UiAlertDelivery::Direct);
    }
}
