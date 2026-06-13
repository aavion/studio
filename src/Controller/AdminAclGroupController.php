<?php

declare(strict_types=1);

namespace App\Controller;

use App\Backend\AdminControllerContext;
use App\Core\Access\AccessLevel;
use App\Core\Id\UuidFactory;
use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Operation\Live\LiveOperationHttpResponder;
use App\Core\Operation\Live\LiveOperationQueueFactory;
use App\Core\Operation\Live\LiveOperationStarter;
use App\Entity\AclGroup;
use App\Security\AclGroupImpactService;
use App\Security\AclGroupMemberProvider;
use App\Security\AdminUserAccessPolicy;
use App\Security\AdminUserListViewFactory;
use App\View\Alert\UiAlertDelivery;
use App\View\Alert\UiAlertDispatcherInterface;
use App\View\Alert\UiAlertTranslation;
use App\View\Http\HttpErrorRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class AdminAclGroupController extends AbstractController
{
    public function __construct(
        private readonly AdminControllerContext $adminContext,
        private readonly HttpErrorRenderer $httpError,
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminUserAccessPolicy $adminUserPolicy,
        private readonly AdminUserListViewFactory $adminUserLists,
        private readonly AclGroupImpactService $aclGroupImpact,
        private readonly AclGroupMemberProvider $memberProvider,
        private readonly LiveOperationStarter $liveOperationStarter,
        private readonly LiveOperationHttpResponder $liveOperationResponder,
        private readonly UuidFactory $uuidFactory,
        private readonly UiAlertDispatcherInterface $alerts,
    ) {
    }

    #[Route('/admin/users/groups', name: 'backend_admin_user_groups', priority: 10, methods: ['GET', 'POST'])]
    public function groups(Request $request): Response
    {
        if ($response = $this->adminContext->accessResponse($request, $this->getUser())) {
            return $response;
        }

        if ($request->isMethod('POST')) {
            $this->createGroup($request);

            return $this->redirectToRoute('backend_admin_user_groups');
        }

        $groupsView = $this->adminUserLists->groupsView($request);

        return $this->render('@backend/admin/users/groups.html.twig', [
            'navigation' => $this->adminContext->navigation($request, $this->getUser()),
            'groups' => $groupsView['items'],
            'groups_view' => $groupsView,
        ]);
    }

    #[Route('/admin/users/groups/details/{identifier}', name: 'backend_admin_user_group_detail', requirements: ['identifier' => '[a-z][a-z0-9_]{2,79}'], priority: 10, methods: ['GET', 'POST'])]
    public function group(Request $request, string $identifier): Response
    {
        if ($response = $this->adminContext->accessResponse($request, $this->getUser())) {
            return $response;
        }

        $group = $this->groupByIdentifier($identifier);

        if (!$group instanceof AclGroup) {
            return $this->httpError->notFound($request);
        }

        if ($request->isMethod('POST')) {
            if ($response = $this->updateGroup($request, $group)) {
                return $response;
            }

            return $this->redirectToRoute('backend_admin_user_group_detail', ['identifier' => $group->identifier()]);
        }

        return $this->render('@backend/admin/users/group-detail.html.twig', [
            'navigation' => $this->adminContext->navigation($request, $this->getUser()),
            'group' => $group,
            'member_count' => $this->memberProvider->count($group),
            'members' => $this->memberProvider->members($group),
        ]);
    }

    #[Route('/admin/users/groups/details/{identifier}/delete', name: 'backend_admin_user_group_delete', requirements: ['identifier' => '[a-z][a-z0-9_]{2,79}'], priority: 10, methods: ['POST'])]
    public function delete(Request $request, string $identifier): Response
    {
        if ($response = $this->adminContext->accessResponse($request, $this->getUser())) {
            return $response;
        }

        $group = $this->groupByIdentifier($identifier);

        if (!$group instanceof AclGroup) {
            return $this->httpError->notFound($request);
        }

        if (!$this->isCsrfTokenValid('admin_group_delete_'.$group->identifier(), $this->field($request, '_csrf_token'))) {
            $this->alertKey('error', 'admin.users.form.errors.invalid_csrf');

            return $this->redirectToRoute('backend_admin_user_group_detail', ['identifier' => $group->identifier()]);
        }

        if ($error = $this->adminUserPolicy->validateGroupDelete($this->adminContext->actor($this->getUser()), $group)) {
            $this->alertKey('error', $error);

            return $this->redirectToRoute('backend_admin_user_group_detail', ['identifier' => $group->identifier()]);
        }

        $impact = $this->aclGroupImpact->impact($group);

        if ('1' !== $this->field($request, 'confirm_delete')) {
            return $this->render('@backend/admin/users/group-review.html.twig', [
                'navigation' => $this->adminContext->navigation($request, $this->getUser()),
                'group' => $group,
                'operation' => 'delete',
                'impact' => $impact,
                'pending' => [],
            ]);
        }

        if ('1' === $this->field($request, '_operation_live')) {
            return $this->startAclGroupLiveOperation($group, 'delete');
        }

        $cleanupImpact = $this->aclGroupImpact->removeReferences($group);
        $this->entityManager->remove($group);
        $this->entityManager->flush();
        $this->adminContext->audit($this->getUser(), 'acl.group_deleted', [
            'group' => $group->identifier(),
            'impact' => $cleanupImpact['summary'],
        ]);
        $this->alertKey('success', 'admin.groups.deleted');

        return $this->redirectToRoute('backend_admin_user_groups');
    }

    private function createGroup(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_group_create', $this->field($request, '_csrf_token'))) {
            $this->alertKey('error', 'admin.users.form.errors.invalid_csrf');

            return;
        }

        try {
            $accessLevel = AccessLevel::assert((int) $this->field($request, 'min_role'));

            if ($error = $this->adminUserPolicy->validateGroupCreate($this->adminContext->actor($this->getUser()), $accessLevel)) {
                $this->alertKey('error', $error);

                return;
            }

            $group = new AclGroup(
                $this->uuidFactory->generate(),
                $this->field($request, 'identifier'),
                $this->field($request, 'name'),
                $accessLevel,
            );
            $this->entityManager->persist($group);
            $this->entityManager->flush();
            $this->adminContext->audit($this->getUser(), 'acl.group_created', ['group' => $group->identifier()]);
            $this->alertKey('success', 'admin.groups.created');
        } catch (Throwable) {
            $this->alertKey('error', 'admin.groups.form.invalid');
        }
    }

    private function updateGroup(Request $request, AclGroup $group): ?Response
    {
        if (!$this->isCsrfTokenValid('admin_group_'.$group->identifier(), $this->field($request, '_csrf_token'))) {
            $this->alertKey('error', 'admin.users.form.errors.invalid_csrf');

            return null;
        }

        $pending = [
            'name' => $this->field($request, 'name'),
            'min_role' => (int) $this->field($request, 'min_role'),
        ];

        if ('' === $pending['name']) {
            $this->alertKey('error', 'admin.groups.form.invalid');

            return null;
        }

        try {
            AccessLevel::assert($pending['min_role']);
        } catch (Throwable) {
            $this->alertKey('error', 'admin.groups.form.invalid');

            return null;
        }

        if ($error = $this->adminUserPolicy->validateGroupUpdate($this->adminContext->actor($this->getUser()), $group, $pending['min_role'])) {
            $this->alertKey('error', $error);

            return null;
        }

        $impact = $this->aclGroupImpact->impact($group);

        if ('1' !== $this->field($request, 'confirm_update')) {
            return $this->render('@backend/admin/users/group-review.html.twig', [
                'navigation' => $this->adminContext->navigation($request, $this->getUser()),
                'group' => $group,
                'operation' => 'update',
                'impact' => $impact,
                'pending' => $pending,
            ]);
        }

        if ('1' === $this->field($request, '_operation_live')) {
            return $this->startAclGroupLiveOperation($group, 'update', $pending);
        }

        try {
            $oldName = $group->name();
            $oldMinRole = $group->minRole();
            $group->rename($pending['name']);
            $floorCleanup = $this->aclGroupImpact->removeBelowMinRoleReferences($group, $pending['min_role']);
            $group->changeMinRole($pending['min_role']);
            $this->entityManager->flush();
            $this->adminContext->audit($this->getUser(), 'acl.group_updated', [
                'group' => $group->identifier(),
                'old_name' => $oldName,
                'new_name' => $group->name(),
                'old_min_role' => $oldMinRole,
                'new_min_role' => $group->minRole(),
                'impact' => $impact['summary'],
                'floor_cleanup' => $floorCleanup,
            ]);
            $this->alertKey('success', 'admin.groups.saved');
        } catch (Throwable) {
            $this->alertKey('error', 'admin.groups.form.invalid');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function startAclGroupLiveOperation(AclGroup $group, string $action, array $payload = []): Response
    {
        $result = $this->liveOperationStarter->start(
            LiveOperationQueueFactory::ACL_GROUP_APPLY,
            [
                'group_uid' => $group->uid(),
                'action' => $action,
                'payload' => $payload,
                'actor_uid' => $this->adminContext->actor($this->getUser())->userUid(),
                'trigger' => 'admin_ui',
            ],
            sprintf('ACL group %s %s', $group->identifier(), $action),
        );
        $this->adminContext->audit($this->getUser(), 'acl.group_'.$action.'_started', [
            'group' => $group->identifier(),
            'group_uid' => $group->uid(),
            'mode' => 'live',
            'result_status' => $result->status()->value,
        ]);

        return $this->liveOperationResponder->render($result);
    }

    private function field(Request $request, string $name): string
    {
        $value = $request->request->get($name);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function groupByIdentifier(string $identifier): ?AclGroup
    {
        $group = $this->entityManager->getRepository(AclGroup::class)->findOneBy(['identifier' => $identifier]);

        return $group instanceof AclGroup ? $group : null;
    }

    private function alertKey(string $level, string $key): void
    {
        $this->alerts->addAlert(UiAlertTranslation::forLevel($level, $key), UiAlertDelivery::Direct);
    }
}
