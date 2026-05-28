<?php

declare(strict_types=1);

namespace App\Controller;

use App\Backend\AdminControllerContext;
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
        private readonly MailLocaleResolver $mailLocaleResolver,
        private readonly UserAccountLifecycle $userLifecycle,
        private readonly AdminUserAccessPolicy $adminUserPolicy,
        private readonly AdminUserListViewFactory $adminUserLists,
        private readonly StateMarkerRecorder $stateMarkers,
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

    #[Route('/admin/users/{uid}', name: 'backend_admin_user_detail', requirements: ['uid' => '[a-f0-9-]{36}'], priority: 10, methods: ['GET', 'POST'])]
    public function user(Request $request, string $uid): Response
    {
        if ($response = $this->adminContext->accessResponse($request, $this->getUser())) {
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

        if (!$user instanceof UserAccount) {
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
        $this->entityManager->persist($token);
        $this->entityManager->flush();
        $this->linkDelivery->deliver($token, AccountMailFlow::PasswordResetLink, $plainToken, $this->generateUrl('user_password_reset_token', ['token' => $plainToken], 0), $this->mailLocaleResolver->forAdminAction($user));
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
