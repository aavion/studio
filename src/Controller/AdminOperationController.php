<?php

declare(strict_types=1);

namespace App\Controller;

use App\Backend\AdminControllerContext;
use App\Backend\AdminOperationFeatureResolver;
use App\Backend\BackendArea;
use App\Core\AdminAcl\AdminFeatureAccessPolicy;
use App\Core\Message\Message;
use App\Core\Operation\Live\LiveOperationRunStore;
use App\Core\Operation\Live\LiveOperationStarter;
use App\Form\FormTokenValidator;
use App\View\Alert\UiAlertDelivery;
use App\View\Alert\UiAlertDispatcherInterface;
use App\View\Alert\UiAlertTranslation;
use App\View\Http\HttpErrorRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminOperationController extends AbstractController
{
    private const FEATURE = 'admin.operations';

    public function __construct(
        private readonly AdminControllerContext $adminContext,
        private readonly HttpErrorRenderer $httpError,
        private readonly LiveOperationRunStore $liveOperationRunStore,
        private readonly LiveOperationStarter $liveOperationStarter,
        private readonly FormTokenValidator $formTokenValidator,
        private readonly UiAlertDispatcherInterface $alerts,
        private readonly AdminFeatureAccessPolicy $adminAcl,
        private readonly AdminOperationFeatureResolver $operationFeatures,
    ) {
    }

    #[Route('/admin/operations', name: 'backend_admin_operations_post', methods: ['POST'], priority: 10)]
    public function maintenance(Request $request): Response
    {
        $access = $this->adminContext->accessResponse($request, $this->getUser());

        if (null !== $access) {
            return $access;
        }
        if ($response = $this->featureResponse($request, mutable: true)) {
            return $response;
        }

        if (!$this->formTokenValidator->isValid('admin-operations', $this->stringField($request, '_form_id'), $this->stringField($request, '_csrf_token'))) {
            $this->alertKey('warning', 'admin.operations.actions.invalid_csrf');

            return $this->redirect($request->getPathInfo());
        }

        $action = $this->stringField($request, '_operations_action');

        if ('cleanup' === $action) {
            $result = $this->liveOperationRunStore->cleanup(3600);
            $this->audit('operations.cleanup', [
                'removed' => $result['removed'],
                'ttl_seconds' => 3600,
            ]);
            $this->alertKey('success', 'admin.operations.actions.cleanup_completed', ['%removed%' => $result['removed']]);

            return $this->redirect($request->getPathInfo());
        }

        if ('clear_stale_lock' === $action && $this->liveOperationRunStore->clearRunnerLock(staleOnly: true, ttlSeconds: 3600)) {
            $this->audit('operations.clear_stale_lock', [
                'ttl_seconds' => 3600,
            ]);
            $this->alertKey('success', 'admin.operations.actions.stale_lock_cleared');

            return $this->redirect($request->getPathInfo());
        }

        if ('kill_stale_runner' === $action) {
            $result = $this->liveOperationRunStore->killStaleRunner(3600);
            $this->audit('operations.kill_stale_runner', [
                'killed' => $result['killed'],
                'lock_cleared' => $result['lock_cleared'],
                'reason' => $result['reason'],
                'pid' => $result['pid'] ?? null,
                'ttl_seconds' => 3600,
            ]);
            $message = 'admin.operations.actions.kill_'.$result['reason'];
            $parameters = ['%pid%' => (string) ($result['pid'] ?? '')];
            $this->alertKey($result['killed'] || $result['lock_cleared'] ? 'success' : 'warning', $message, $parameters);

            return $this->redirect($request->getPathInfo());
        }

        $this->audit('operations.noop', [
            'requested_action' => $action,
        ]);
        $this->alertKey('warning', 'admin.operations.actions.noop');

        return $this->redirect($request->getPathInfo());
    }

    #[Route('/admin/operations/{operationId}', name: 'backend_admin_operation_detail', requirements: ['operationId' => '[a-f0-9]{32}'], methods: ['GET'])]
    public function detail(Request $request, string $operationId): Response
    {
        $access = $this->adminContext->accessResponse($request, $this->getUser());

        if (null !== $access) {
            return $access;
        }
        if ($response = $this->featureResponse($request, mutable: false)) {
            return $response;
        }

        $report = $this->liveOperationRunStore->report($operationId);

        if (null === $report) {
            return $this->httpError->render(Response::HTTP_NOT_FOUND, $request, context: [
                'area' => BackendArea::Admin->value,
                'operation_id' => $operationId,
            ]);
        }

        return $this->render('@backend/admin/operations/detail.html.twig', [
            'area' => BackendArea::Admin,
            'navigation' => $this->adminContext->navigation($request, $this->getUser()),
            'operation_report' => $report,
            'operations_mutable' => $this->adminAcl->isMutable(self::FEATURE, $this->adminContext->actor($this->getUser())),
            'operation_continuation_mutable' => $this->operationContinuationMutable($operationId),
        ]);
    }

    #[Route('/admin/operations/{operationId}/continue', name: 'backend_admin_operation_continue', requirements: ['operationId' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function continue(Request $request, string $operationId): Response
    {
        $access = $this->adminContext->accessResponse($request, $this->getUser());

        if (null !== $access) {
            return $access;
        }
        if ($response = $this->featureResponse($request, mutable: true)) {
            return $response;
        }

        if (!$this->formTokenValidator->isValid('admin-operations', 'admin-operations', $this->stringField($request, '_csrf_token'))) {
            $this->alertKey('warning', 'admin.operations.actions.invalid_csrf');

            return $this->redirectToRoute('backend_admin_operation_detail', ['operationId' => $operationId]);
        }

        $continuation = $this->liveOperationRunStore->continuationForOperator($operationId);

        if (null === $continuation) {
            return $this->redirectToRoute('backend_admin_operation_detail', ['operationId' => $operationId]);
        }

        $targetFeature = $this->operationFeatures->mutationFeatureForOperation((string) $continuation['operation']);
        if (is_string($targetFeature) && !$this->adminAcl->isMutable($targetFeature, $this->adminContext->actor($this->getUser()))) {
            return $this->httpError->render(Response::HTTP_UNAUTHORIZED, $request, context: [
                'feature' => $targetFeature,
                'required_state' => 'mutable',
            ]);
        }

        $result = $this->liveOperationStarter->start(
            $continuation['operation'],
            $continuation['payload'],
            $continuation['label'],
        );

        if ($result->isSuccess() && is_array($result->value())) {
            $continuedOperationId = $result->value()['operation_id'] ?? null;

            if (is_string($continuedOperationId) && '' !== $continuedOperationId) {
                return $this->redirectToRoute('backend_admin_operation_detail', ['operationId' => $continuedOperationId]);
            }
        }

        foreach ($result->issues() as $issue) {
            $this->alert($issue);
        }

        return $this->redirectToRoute('backend_admin_operation_detail', ['operationId' => $operationId]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function audit(string $action, array $context = []): void
    {
        $this->adminContext->audit($this->getUser(), $action, [
            ...$context,
            'result_status' => 'success',
        ]);
    }

    private function featureResponse(Request $request, bool $mutable): ?Response
    {
        $actor = $this->adminContext->actor($this->getUser());
        $allowed = $mutable ? $this->adminAcl->isMutable(self::FEATURE, $actor) : $this->adminAcl->isVisible(self::FEATURE, $actor);

        if ($allowed) {
            return null;
        }

        return $this->httpError->render(Response::HTTP_UNAUTHORIZED, $request, context: [
            'feature' => self::FEATURE,
            'required_state' => $mutable ? 'mutable' : 'visible',
        ]);
    }

    private function operationContinuationMutable(string $operationId): bool
    {
        if (!$this->adminAcl->isMutable(self::FEATURE, $this->adminContext->actor($this->getUser()))) {
            return false;
        }

        $continuation = $this->liveOperationRunStore->continuationForOperator($operationId);
        if (null === $continuation) {
            return false;
        }

        $targetFeature = $this->operationFeatures->mutationFeatureForOperation((string) $continuation['operation']);

        return !is_string($targetFeature) || $this->adminAcl->isMutable($targetFeature, $this->adminContext->actor($this->getUser()));
    }

    private function stringField(Request $request, string $name): string
    {
        $value = $request->request->get($name);

        return is_string($value) ? $value : '';
    }

    private function alert(Message $message): void
    {
        $this->alerts->addAlert($message, UiAlertDelivery::Direct);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function alertKey(string $level, string $key, array $parameters = []): void
    {
        $this->alerts->addAlert(UiAlertTranslation::forLevel($level, $key, $parameters), UiAlertDelivery::Direct);
    }
}
