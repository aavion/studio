<?php

declare(strict_types=1);

namespace App\Backend;

use App\Backend\BackendMessageKey;
use App\Core\Access\AccessActor;
use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Operation\Live\LiveOperationHttpResponder;
use App\Core\Workflow\WorkflowResult;
use App\Entity\UserAccount;
use App\Form\FormTokenValidator;
use App\View\Alert\UiAlertDelivery;
use App\View\Alert\UiAlertDispatcherInterface;
use App\View\Alert\WorkflowResultAlertSelector;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class BackendActionResponder
{
    public function __construct(
        private BackendActions $backendActions,
        private AdminControllerContext $adminContext,
        private LiveOperationHttpResponder $liveOperationResponder,
        private FormTokenValidator $formTokenValidator,
        private UiAlertDispatcherInterface $alerts,
        private WorkflowResultAlertSelector $alertSelector,
    ) {
    }

    public function supports(Request $request): bool
    {
        return '' !== $this->stringField($request, '_backend_action');
    }

    public function respond(Request $request, mixed $user): Response
    {
        $action = $this->stringField($request, '_backend_action');
        $validToken = $this->formTokenValidator->isValid(
            'backend-action-'.$action,
            $this->stringField($request, '_form_id'),
            $this->stringField($request, '_csrf_token'),
        );

        if ('1' === $this->stringField($request, '_operation_live')) {
            $result = $validToken ? $this->backendActions->startLive($action, $this->actor($user)) : $this->invalidCsrfResult($action);
            $this->audit($user, $action, $result, 'live');

            return $this->liveOperationResponder->render($result);
        }

        $result = $validToken ? $this->backendActions->run($action, $this->actor($user)) : $this->invalidCsrfResult($action);
        $this->flashResult($result);
        $this->audit($user, $action, $result, 'sync');

        return new RedirectResponse($request->getPathInfo());
    }

    /**
     * @return WorkflowResult<mixed>
     */
    private function invalidCsrfResult(string $action): WorkflowResult
    {
        return WorkflowResult::invalid([
            Message::warning(
                CommonMessageCode::E_INVALID_ARGUMENT,
                BackendMessageKey::BACKEND_ACTION_INVALID_CSRF,
                context: ['action' => $action],
            ),
        ], ['action' => $action]);
    }

    private function actor(mixed $user): AccessActor
    {
        return $user instanceof UserAccount ? AccessActor::fromUserAccount($user) : AccessActor::anonymous();
    }

    /**
     * @param WorkflowResult<mixed> $result
     */
    private function audit(mixed $user, string $action, WorkflowResult $result, string $mode): void
    {
        $this->adminContext->audit($user, 'backend.action.'.$action, [
            'action' => $action,
            'mode' => $mode,
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
