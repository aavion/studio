<?php

declare(strict_types=1);

namespace App\Controller;

use App\Backend\BackendAccessGuard;
use App\Backend\BackendArea;
use App\Core\Access\AccessActor;
use App\Core\AdminAcl\AdminFeatureAccessPolicy;
use App\Core\Log\AccessRequestMetadata;
use App\Core\Log\AuditLoggerInterface;
use App\Entity\UserAccount;
use App\Form\FormTokenValidator;
use App\Security\Abuse\SecuritySignalRecorder;
use App\Security\AutoBan\ActiveAutoBan;
use App\Security\AutoBan\AutoBanAdminBrowser;
use App\Security\AutoBan\AutoBanResetService;
use App\Security\AutoBan\AutoBanScoreCatalogue;
use App\View\Alert\UiAlertDelivery;
use App\View\Alert\UiAlertDispatcherInterface;
use App\View\Alert\UiAlertTranslation;
use App\View\Http\HttpErrorRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class AdminAutoBanController extends AbstractController
{
    public function __construct(
        private readonly BackendAccessGuard $accessGuard,
        private readonly AdminFeatureAccessPolicy $adminAcl,
        private readonly AutoBanAdminBrowser $browser,
        private readonly AutoBanResetService $resetService,
        private readonly SecuritySignalRecorder $signals,
        private readonly HttpErrorRenderer $httpError,
        private readonly FormTokenValidator $formTokenValidator,
        private readonly AccessRequestMetadata $requestMetadata,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly UiAlertDispatcherInterface $alerts,
    ) {
    }

    #[Route('/admin/security/auto-bans', name: 'backend_admin_auto_bans', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($response = $this->accessResponse($request, mutable: false)) {
            return $response;
        }

        return $this->render('@backend/admin/security/auto-bans.html.twig', [
            'area' => BackendArea::Admin,
            'active_auto_bans' => $this->browser->activeList(),
        ]);
    }

    #[Route('/admin/security/auto-bans/{key}', name: 'backend_admin_auto_ban_detail', requirements: ['key' => '[a-f0-9]{40}'], methods: ['GET'])]
    public function detail(Request $request, string $key): Response
    {
        if ($response = $this->accessResponse($request, mutable: false)) {
            return $response;
        }

        $detail = $this->browser->detail($key);
        if (null === $detail) {
            return $this->httpError->notFound($request);
        }

        return $this->render('@backend/admin/security/auto-ban-detail.html.twig', [
            'area' => BackendArea::Admin,
            'auto_ban_detail' => $detail,
            'auto_ban_reset_mutable' => $this->adminAcl->isMutable('admin.settings.security', $this->actor()),
            'reset_form_id' => $this->resetFormId($key),
        ]);
    }

    #[Route('/admin/security/auto-bans/{key}/reset', name: 'backend_admin_auto_ban_reset', requirements: ['key' => '[a-f0-9]{40}'], methods: ['POST'])]
    public function reset(Request $request, string $key): Response
    {
        if ($response = $this->accessResponse($request, mutable: true)) {
            return $response;
        }

        if (!$this->formTokenValidator->isValid($this->resetFormId($key), (string) $request->request->get('_form_id', ''), (string) $request->request->get('_csrf_token', ''))) {
            return $this->httpError->resolve(Response::HTTP_FORBIDDEN, $request, context: ['auto_ban_key' => $key]);
        }

        $ban = $this->resetService->releaseAndRecord($key, fn (ActiveAutoBan $released): bool => $this->recordResetSignal($request, $key, $released));
        if ($ban instanceof ActiveAutoBan) {
            $this->auditReset($key, $ban->subjectType());
            $this->alerts->addAlert(UiAlertTranslation::success('admin.auto_bans.reset.saved'), UiAlertDelivery::Direct);
        } else {
            $this->alerts->addAlert(UiAlertTranslation::error('admin.auto_bans.reset.failed'), UiAlertDelivery::Direct);
        }

        return $this->redirectToRoute('backend_admin_auto_bans');
    }

    private function accessResponse(Request $request, bool $mutable): ?Response
    {
        $decision = $this->accessGuard->decide(BackendArea::Admin, $this->getUser());
        if (!$decision->isGranted()) {
            return $this->httpError->resolve(Response::HTTP_UNAUTHORIZED, $request, context: [
                'area' => BackendArea::Admin->value,
                'access_decision' => $decision->toArray(),
            ]);
        }

        $actor = $this->actor();
        $allowed = $mutable
            ? $this->adminAcl->isMutable('admin.settings.security', $actor)
            : $this->adminAcl->isVisible('admin.settings.security', $actor);
        if (!$allowed) {
            return $this->httpError->resolve(Response::HTTP_UNAUTHORIZED, $request, context: [
                'feature' => 'admin.settings.security',
                'required_state' => $mutable ? 'mutable' : 'visible',
            ]);
        }

        return null;
    }

    private function actor(): AccessActor
    {
        $user = $this->getUser();

        return $user instanceof UserAccount ? AccessActor::fromUserAccount($user) : AccessActor::anonymous();
    }

    private function resetFormId(string $key): string
    {
        return 'admin-auto-ban-reset-'.$key;
    }

    private function recordResetSignal(Request $request, string $key, ActiveAutoBan $ban): bool
    {
        return $this->signals->record(
            'auto_ban',
            AutoBanScoreCatalogue::SIGNAL_RESET,
            $ban->subjectType(),
            $ban->subjectIdentifier(),
            ipDerived: 'ip_bucket' === $ban->subjectType(),
            severity: 'NOTICE',
            confidence: 100,
            requestFamily: 'admin',
            requestIntent: 'settings_mutation',
            requestId: $this->requestMetadata->requestId($request),
            visitorId: 'n/a',
            path: $this->requestMetadata->sanitizedPath($request),
            route: 'backend_admin_auto_ban_reset',
            context: [
                'active_ban_key' => $key,
                'effective_subject_type' => 'ip_bucket' === $ban->subjectType() ? 'ip' : 'visitor',
                'reset_by' => $this->actor()->userUid(),
            ],
        );
    }

    private function auditReset(string $key, string $subjectType): void
    {
        try {
            $this->auditLogger->log($this->actor(), 'security.auto_ban.reset', [
                'active_ban_key' => $key,
                'subject_type' => $subjectType,
            ]);
        } catch (Throwable) {
            return;
        }
    }
}
