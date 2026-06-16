<?php

declare(strict_types=1);

namespace App\Controller;

use App\Backend\BackendAccessGuard;
use App\Backend\BackendArea;
use App\Core\Access\AccessActor;
use App\Core\AdminAcl\AdminFeatureAccessPolicy;
use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Entity\UserAccount;
use App\Security\AdminUserInvitationWorkflow;
use App\Security\UserRole;
use App\View\Alert\UiAlertDelivery;
use App\View\Alert\UiAlertDispatcherInterface;
use App\View\Alert\UiAlertTranslation;
use App\View\Http\HttpErrorRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminUserInvitationController extends AbstractController
{
    public function __construct(
        private readonly BackendAccessGuard $accessGuard,
        private readonly HttpErrorRenderer $httpError,
        private readonly AdminUserInvitationWorkflow $invitationWorkflow,
        private readonly UiAlertDispatcherInterface $alerts,
        private readonly AdminFeatureAccessPolicy $adminAcl,
    ) {
    }

    #[Route('/admin/users/invitations', name: 'backend_admin_user_invite', priority: 10, methods: ['POST'])]
    public function invite(Request $request): Response
    {
        if ($response = $this->adminAccessResponse($request)) {
            return $response;
        }
        if ($response = $this->featureResponse($request, 'admin.users')) {
            return $response;
        }

        if (!$this->isCsrfTokenValid('admin_user_invite', $this->field($request, '_csrf_token'))) {
            $this->alertKey('error', 'admin.users.form.errors.invalid_csrf');

            return $this->redirectAfterTokenAction($request);
        }

        $groups = $this->groupIdentifiers($request->request->all('groups'));
        $role = UserRole::tryFrom($this->field($request, 'role'));
        $result = $this->invitationWorkflow->invite($this->actor(), $this->field($request, 'email'), $role, $groups);
        $this->alertKey($result->successLevel(), $result->flashKey());

        return $this->redirectToRoute('backend_admin_users');
    }

    #[Route('/admin/users/invitations/{uid}/approve', name: 'backend_admin_user_invitation_approve', priority: 10, methods: ['POST'])]
    public function approve(Request $request, string $uid): Response
    {
        if ($response = $this->adminAccessResponse($request)) {
            return $response;
        }
        if ($response = $this->featureResponse($request, 'admin.users.review')) {
            return $response;
        }

        if (!$this->isCsrfTokenValid('admin_user_token_'.$uid, $this->field($request, '_csrf_token'))) {
            $this->alertKey('error', 'admin.users.form.errors.invalid_csrf');

            return $this->redirectAfterTokenAction($request);
        }

        $result = $this->invitationWorkflow->approve($this->actor(), $uid);
        $this->alertKey($result->successLevel(), $result->flashKey());

        return $this->redirectAfterTokenAction($request);
    }

    #[Route('/admin/users/invitations/{uid}/reissue', name: 'backend_admin_user_invitation_reissue', priority: 10, methods: ['POST'])]
    public function reissue(Request $request, string $uid): Response
    {
        if ($response = $this->adminAccessResponse($request)) {
            return $response;
        }
        if ($response = $this->featureResponse($request, $this->tokenActionFeature($request))) {
            return $response;
        }

        if (!$this->isCsrfTokenValid('admin_user_token_'.$uid, $this->field($request, '_csrf_token'))) {
            $this->alertKey('error', 'admin.users.form.errors.invalid_csrf');

            return $this->redirectAfterTokenAction($request);
        }

        $result = $this->invitationWorkflow->reissue($this->actor(), $uid);
        $this->alertKey($result->successLevel(), $result->flashKey());

        return $this->redirectAfterTokenAction($request);
    }

    #[Route('/admin/users/invitations/{uid}/revoke', name: 'backend_admin_user_invitation_revoke', priority: 10, methods: ['POST'])]
    public function revoke(Request $request, string $uid): Response
    {
        if ($response = $this->adminAccessResponse($request)) {
            return $response;
        }
        if ($response = $this->featureResponse($request, $this->tokenActionFeature($request))) {
            return $response;
        }

        if (!$this->isCsrfTokenValid('admin_user_token_'.$uid, $this->field($request, '_csrf_token'))) {
            $this->alertKey('error', 'admin.users.form.errors.invalid_csrf');

            return $this->redirectAfterTokenAction($request);
        }

        $result = $this->invitationWorkflow->revoke($this->actor(), $uid);
        $this->alertKey($result->successLevel(), $result->flashKey());

        return $this->redirectAfterTokenAction($request);
    }

    private function redirectAfterTokenAction(Request $request): Response
    {
        return 'reviews' === $this->field($request, 'return_to')
            ? $this->redirectToRoute('backend_admin_user_reviews')
            : $this->redirectToRoute('backend_admin_users');
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

    private function featureResponse(Request $request, string $feature): ?Response
    {
        if ($this->adminAcl->isMutable($feature, $this->actor())) {
            return null;
        }

        return $this->httpError->render(Response::HTTP_UNAUTHORIZED, $request, context: [
            'feature' => $feature,
            'required_state' => 'mutable',
        ]);
    }

    private function tokenActionFeature(Request $request): string
    {
        return 'reviews' === $this->field($request, 'return_to') ? 'admin.users.review' : 'admin.users';
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

    private function alertKey(string $level, string $key): void
    {
        $this->alerts->addAlert(UiAlertTranslation::forLevel($level, $key), UiAlertDelivery::Direct);
    }

}
