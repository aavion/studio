<?php

declare(strict_types=1);

namespace App\Controller;

use App\Backend\BackendAccessGuard;
use App\Backend\BackendArea;
use App\Core\Access\AccessActor;
use App\Core\Log\AuditLoggerInterface;
use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\State\StateMarkerKey;
use App\Core\State\StateMarkerRecorder;
use App\Core\State\StateSubjectType;
use App\Entity\AccountToken;
use App\Entity\UserAccount;
use App\Mail\AccountMailFlow;
use App\Mail\MailLocaleResolver;
use App\Navigation\NavigationBuilder;
use App\Security\AccountLinkDeliveryInterface;
use App\Security\AccountTokenStatus;
use App\Security\AccountTokenType;
use App\Security\AdminUserAccessPolicy;
use App\Security\AdminUserReviewViewFactory;
use App\Security\UserAccountLifecycle;
use App\Security\UserAccountStatus;
use App\View\Alert\UiAlertDelivery;
use App\View\Alert\UiAlertDispatcherInterface;
use App\View\Alert\UiAlertTranslation;
use App\View\Http\HttpErrorRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class AdminUserReviewController extends AbstractController
{
    public function __construct(
        private readonly BackendAccessGuard $accessGuard,
        private readonly HttpErrorRenderer $httpError,
        private readonly NavigationBuilder $navigationBuilder,
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountLinkDeliveryInterface $linkDelivery,
        private readonly MailLocaleResolver $mailLocaleResolver,
        private readonly UserAccountLifecycle $userLifecycle,
        private readonly AdminUserAccessPolicy $adminUserPolicy,
        private readonly AdminUserReviewViewFactory $adminUserReviews,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly StateMarkerRecorder $stateMarkers,
        private readonly UiAlertDispatcherInterface $alerts,
    ) {
    }

    #[Route('/admin/users/reviews', name: 'backend_admin_user_reviews', priority: 10, methods: ['GET'])]
    public function reviews(Request $request): Response
    {
        if ($response = $this->adminAccessResponse($request)) {
            return $response;
        }

        $reviewView = $this->adminUserReviews->reviewView($request);

        return $this->render('@backend/admin/users/reviews.html.twig', [
            'navigation' => $this->navigation($request),
            'review_items' => $reviewView['items'],
            'review_filter' => $reviewView['filters']['filter'],
            'review_view' => $reviewView,
            'review_filters' => $reviewView['review_filters'],
        ]);
    }

    #[Route('/admin/users/reviews/details/{username}/reactivate', name: 'backend_admin_user_review_reactivate', requirements: ['username' => '[A-Za-z][A-Za-z0-9_-]{4,29}'], priority: 10, methods: ['POST'])]
    public function reactivate(Request $request, string $username): Response
    {
        if ($response = $this->adminAccessResponse($request)) {
            return $response;
        }

        $user = $this->userByUsername($username);

        if (!$user instanceof UserAccount) {
            return $this->httpError->notFound($request);
        }

        if (!$this->isCsrfTokenValid('admin_user_review_'.$user->username(), $this->field($request, '_csrf_token'))) {
            $this->alertKey('error', 'admin.users.form.errors.invalid_csrf');

            return $this->redirectToRoute('backend_admin_user_reviews');
        }

        if (!$this->hasUnresolvedSecurityReview($user)) {
            $this->alertKey('error', 'admin.users.invitation.unavailable');

            return $this->redirectToRoute('backend_admin_user_reviews');
        }

        if ($error = $this->adminUserPolicy->validateUserAction($this->actor(), $user)) {
            $this->alertKey('error', $error);

            return $this->redirectToRoute('backend_admin_user_reviews');
        }

        $user->changePassword($this->passwordHasher->hashPassword($user, bin2hex(random_bytes(32))));
        $this->stateMarkers->record(StateSubjectType::USER_ACCOUNT, $user->uid(), StateMarkerKey::PASSWORD_CHANGED, $this->actorName(), 'reactivated');
        $this->userLifecycle->changeStatus($user, UserAccountStatus::Active, $this->actorName());
        $this->deleteUsedSecurityReviewTokens($user);
        $this->entityManager->flush();
        $this->linkDelivery->notifyAddress($user->email(), AccountMailFlow::PasswordChangeReactivated, $this->mailLocaleResolver->forAdminAction($user), [
            'username' => $user->username(),
            'user_uid' => $user->uid(),
        ]);
        $this->audit('user.security_review_reactivated', ['target_user' => $user->uid()]);
        $this->alertKey('success', 'admin.user_reviews.actions.reactivated');

        return $this->redirectToRoute('backend_admin_user_reviews');
    }

    #[Route('/admin/users/reviews/details/{username}/delete', name: 'backend_admin_user_review_delete', requirements: ['username' => '[A-Za-z][A-Za-z0-9_-]{4,29}'], priority: 10, methods: ['POST'])]
    public function delete(Request $request, string $username): Response
    {
        if ($response = $this->adminAccessResponse($request)) {
            return $response;
        }

        $user = $this->userByUsername($username);

        if (!$user instanceof UserAccount) {
            return $this->httpError->notFound($request);
        }

        if (!$this->isCsrfTokenValid('admin_user_review_'.$user->username(), $this->field($request, '_csrf_token'))) {
            $this->alertKey('error', 'admin.users.form.errors.invalid_csrf');

            return $this->redirectToRoute('backend_admin_user_reviews');
        }

        if ('1' !== $this->field($request, 'confirm_delete')) {
            $this->alertKey('error', 'admin.user_reviews.actions.delete_confirmation_required');

            return $this->redirectToRoute('backend_admin_user_reviews');
        }

        if (!$this->hasUnresolvedSecurityReview($user)) {
            $this->alertKey('error', 'admin.users.invitation.unavailable');

            return $this->redirectToRoute('backend_admin_user_reviews');
        }

        if ($error = $this->adminUserPolicy->validateUserAction($this->actor(), $user)) {
            $this->alertKey('error', $error);

            return $this->redirectToRoute('backend_admin_user_reviews');
        }

        if (!$this->adminUserPolicy->allowsAccountClosure($user)) {
            $this->alertKey('error', 'admin.users.form.errors.last_owner');

            return $this->redirectToRoute('backend_admin_user_reviews');
        }

        $effects = $this->userLifecycle->changeStatus($user, UserAccountStatus::Deleted, $this->actorName());
        $this->deleteUsedSecurityReviewTokens($user);
        $this->entityManager->flush();
        $this->audit('user.security_review_deleted', ['target_user' => $user->uid(), ...$effects]);
        $this->alertKey('success', 'admin.user_reviews.actions.deleted');

        return $this->redirectToRoute('backend_admin_user_reviews');
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

    private function actorName(): ?string
    {
        return $this->actor()->username();
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

    private function deleteUsedSecurityReviewTokens(UserAccount $user): void
    {
        $tokens = $this->entityManager->getRepository(AccountToken::class)->findBy([
            'user' => $user,
            'type' => AccountTokenType::SecurityReview,
            'status' => AccountTokenStatus::Used,
        ]);

        foreach ($tokens as $token) {
            if ($token instanceof AccountToken) {
                $this->entityManager->remove($token);
            }
        }
    }

    private function hasUnresolvedSecurityReview(UserAccount $user): bool
    {
        if (UserAccountStatus::Inactive !== $user->status()) {
            return false;
        }

        return $this->entityManager->getRepository(AccountToken::class)->findOneBy([
            'user' => $user,
            'type' => AccountTokenType::SecurityReview,
            'status' => AccountTokenStatus::Used,
        ]) instanceof AccountToken;
    }

    private function userByUsername(string $username): ?UserAccount
    {
        $user = $this->entityManager->getRepository(UserAccount::class)->findOneBy(['username' => $username]);

        return $user instanceof UserAccount ? $user : null;
    }

    private function alertKey(string $level, string $key): void
    {
        $this->alerts->addAlert(UiAlertTranslation::forLevel($level, $key), UiAlertDelivery::Direct);
    }
}
