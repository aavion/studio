<?php

declare(strict_types=1);

namespace App\Controller;

use App\Backend\AdminControllerContext;
use App\Backend\BackendActionResponder;
use App\Backend\BackendArea;
use App\Backend\BackendMessageKey;
use App\Backend\PackageLifecycleAdmin;
use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Operation\Live\LiveOperationHttpResponder;
use App\Core\Operation\Live\LiveOperationQueueFactory;
use App\Core\Operation\Live\LiveOperationStarter;
use App\Core\Package\Install\PackageZipInstaller;
use App\Core\Workflow\WorkflowResult;
use App\Form\FormTokenValidator;
use App\View\Alert\UiAlertDelivery;
use App\View\Alert\UiAlertDispatcherInterface;
use App\View\Alert\WorkflowResultAlertSelector;
use App\View\Http\HttpErrorRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminPackageController extends AbstractController
{
    public function __construct(
        private readonly AdminControllerContext $adminContext,
        private readonly HttpErrorRenderer $httpError,
        private readonly PackageLifecycleAdmin $packageLifecycleAdmin,
        private readonly PackageZipInstaller $packageZipInstaller,
        private readonly LiveOperationStarter $liveOperationStarter,
        private readonly BackendActionResponder $backendActionResponder,
        private readonly LiveOperationHttpResponder $liveOperationResponder,
        private readonly FormTokenValidator $formTokenValidator,
        private readonly UiAlertDispatcherInterface $alerts,
        private readonly WorkflowResultAlertSelector $alertSelector,
    ) {
    }

    #[Route('/admin/packages/install', name: 'backend_admin_package_install', methods: ['POST'])]
    public function install(Request $request): Response
    {
        $access = $this->adminContext->accessResponse($request, $this->getUser());

        if (null !== $access) {
            return $access;
        }

        $validToken = $this->formTokenValidator->isValid('package-install', $this->stringField($request, '_form_id'), $this->stringField($request, '_csrf_token'));

        if (!$validToken) {
            $result = WorkflowResult::invalid([
                Message::warning(
                    CommonMessageCode::E_INVALID_ARGUMENT,
                    BackendMessageKey::BACKEND_ACTION_INVALID_CSRF,
                    context: ['action' => 'package_install'],
                ),
            ]);

            if ('1' === $this->stringField($request, '_operation_live')) {
                return $this->liveOperationResponder->render($result);
            }

            $this->flashResult($result);

            return $this->redirect('/admin/packages');
        }

        $uploaded = $request->files->get('package_zip');
        $stage = $this->packageZipInstaller->stageUpload($uploaded instanceof UploadedFile ? $uploaded : null);

        if (!$stage->isSuccess()) {
            if ('1' === $this->stringField($request, '_operation_live')) {
                return $this->liveOperationResponder->render($stage);
            }

            $this->flashResult($stage);

            return $this->redirect('/admin/packages');
        }

        $result = $this->liveOperationStarter->start(
            LiveOperationQueueFactory::PACKAGE_INSTALL_VERIFY,
            [
                'install_id' => $stage->value()['install_id'],
                'trigger' => 'admin_ui',
            ],
            'Verify package ZIP',
        );
        $this->auditResult('package.install_verify_started', $result, [
            'operation' => LiveOperationQueueFactory::PACKAGE_INSTALL_VERIFY,
        ]);

        if ('1' === $this->stringField($request, '_operation_live')) {
            return $this->liveOperationResponder->render($result);
        }

        $this->flashResult($result);

        return $this->redirect('/admin/operations');
    }

    #[Route('/admin/packages/{packageName}', name: 'backend_admin_package_detail', requirements: ['packageName' => '[^/]+'], methods: ['GET', 'POST'])]
    public function detail(Request $request, string $packageName): Response
    {
        $access = $this->adminContext->accessResponse($request, $this->getUser());

        if (null !== $access) {
            return $access;
        }

        if ($request->isMethod('POST')) {
            if ($this->backendActionResponder->supports($request)) {
                return $this->backendActionResponder->respond($request, $this->getUser());
            }

            return $this->httpError->render(Response::HTTP_METHOD_NOT_ALLOWED, $request, context: [
                'area' => BackendArea::Admin->value,
                'package' => $packageName,
            ]);
        }

        $package = $this->packageLifecycleAdmin->package($packageName);

        if (null === $package) {
            return $this->httpError->render(Response::HTTP_NOT_FOUND, $request, context: [
                'area' => BackendArea::Admin->value,
                'package' => $packageName,
            ]);
        }

        return $this->render('@backend/admin/packages/detail.html.twig', [
            'area' => BackendArea::Admin,
            'navigation' => $this->adminContext->navigation($request, $this->getUser()),
            'package' => $package,
        ]);
    }

    #[Route('/admin/packages/{packageName}/{action}', name: 'backend_admin_package_lifecycle', requirements: ['packageName' => '[^/]+', 'action' => 'activate|deactivate|reset-fault|purge|delete'], methods: ['GET', 'POST'])]
    public function lifecycle(Request $request, string $packageName, string $action): Response
    {
        $access = $this->adminContext->accessResponse($request, $this->getUser());

        if (null !== $access) {
            return $access;
        }

        if ($request->isMethod('POST') && $this->backendActionResponder->supports($request)) {
            return $this->backendActionResponder->respond($request, $this->getUser());
        }

        $review = $this->packageLifecycleAdmin->review($packageName, $action);

        if (null === $review['package']) {
            return $this->httpError->render(Response::HTTP_NOT_FOUND, $request, context: [
                'area' => BackendArea::Admin->value,
                'package' => $packageName,
                'action' => $action,
            ]);
        }

        if ($request->isMethod('POST')) {
            $formId = 'package-lifecycle-'.$action.'-'.$packageName;

            if (!$this->formTokenValidator->isValid($formId, $this->stringField($request, '_form_id'), $this->stringField($request, '_csrf_token'))) {
                $this->alerts->addAlert(
                    Message::invalidArgument(BackendMessageKey::BACKEND_ACTION_INVALID_CSRF),
                    UiAlertDelivery::Direct,
                );

                return $this->redirect($request->getPathInfo());
            }

            if ('1' === $this->stringField($request, '_operation_live')) {
                return $this->handleLivePackageLifecycle($packageName, $action);
            }

            $result = $this->packageLifecycleAdmin->apply($packageName, $action);
            $this->auditResult('package.lifecycle.'.$action, $result, [
                'package' => $packageName,
                'action' => $action,
                'mode' => 'sync',
            ]);
            $this->flashResult($result);

            if ($result->isSuccess()) {
                return $this->redirect('purge' === $action ? '/admin/packages' : '/admin/packages/'.rawurlencode($packageName));
            }

            return $this->redirect($request->getPathInfo());
        }

        return $this->render('@backend/admin/packages/lifecycle.html.twig', [
            'area' => BackendArea::Admin,
            'navigation' => $this->adminContext->navigation($request, $this->getUser()),
            'review' => $review,
        ]);
    }

    private function handleLivePackageLifecycle(string $packageName, string $action): Response
    {
        $label = sprintf('Package %s %s', $packageName, $action);
        $result = $this->liveOperationStarter->start(
            LiveOperationQueueFactory::PACKAGE_LIFECYCLE,
            ['package' => $packageName, 'action' => $action, 'trigger' => 'admin_ui'],
            $label,
        );
        $this->auditResult('package.lifecycle.'.$action, $result, [
            'package' => $packageName,
            'action' => $action,
            'mode' => 'live',
        ]);

        return $this->liveOperationResponder->render($result);
    }

    /**
     * @param WorkflowResult<mixed> $result
     * @param array<string, mixed> $context
     */
    private function auditResult(string $action, WorkflowResult $result, array $context = []): void
    {
        $this->adminContext->audit($this->getUser(), $action, [
            ...$context,
            'result_status' => $result->status()->value,
        ]);
    }

    /**
     * @param WorkflowResult<mixed> $result
     */
    private function flashResult(WorkflowResult $result): void
    {
        $this->alerts->addAlert($this->alertSelector->fromResult($result), UiAlertDelivery::Direct);
    }

    private function stringField(Request $request, string $name): string
    {
        $value = $request->request->get($name);

        return is_string($value) ? $value : '';
    }
}
