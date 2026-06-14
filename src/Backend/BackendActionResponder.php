<?php

declare(strict_types=1);

namespace App\Backend;

use App\Backend\BackendMessageKey;
use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Operation\Live\LiveOperationHttpResponder;
use App\Core\Operation\OperationMessageKey;
use App\Core\Workflow\WorkflowResult;
use App\Form\FormTokenValidator;
use App\View\Alert\UiAlertDelivery;
use App\View\Alert\UiAlertDispatcherInterface;
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
            $result = $validToken ? $this->backendActions->startLive($action) : $this->invalidCsrfResult($action);
            $this->audit($user, $action, $result, 'live');

            return $this->liveOperationResponder->render($result);
        }

        $result = $validToken ? $this->backendActions->run($action) : $this->invalidCsrfResult($action);
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
        $message = $result->isSuccess()
            ? ($this->firstMessageWithLevel($result, MessageLevel::Success) ?? Message::success(BackendMessageKey::BACKEND_ACTION_CACHE_CLEAR_COMPLETED))
            : ($result->firstIssue() ?? Message::error(CommonMessageCode::E_OPERATION_FAILED, OperationMessageKey::OPERATION_EXCEPTION));

        $this->alerts->addAlert($message, UiAlertDelivery::Direct);
    }

    /**
     * @param WorkflowResult<mixed> $result
     */
    private function firstMessageWithLevel(WorkflowResult $result, MessageLevel $level): ?Message
    {
        foreach ($result->messages() as $message) {
            if ($message->level() === $level) {
                return $message;
            }
        }

        return null;
    }

    private function stringField(Request $request, string $name): string
    {
        $value = $request->request->get($name);

        return is_string($value) ? $value : '';
    }
}
