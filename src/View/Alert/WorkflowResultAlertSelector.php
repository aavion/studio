<?php

declare(strict_types=1);

namespace App\View\Alert;

use App\Backend\BackendMessageKey;
use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Operation\OperationMessageKey;
use App\Core\Workflow\WorkflowResult;

final readonly class WorkflowResultAlertSelector
{
    /**
     * @param WorkflowResult<mixed> $result
     */
    public function fromResult(WorkflowResult $result, string $fallbackSuccessKey = BackendMessageKey::BACKEND_ACTION_CACHE_CLEAR_COMPLETED): Message
    {
        if (!$result->isSuccess()) {
            return $result->firstIssue()
                ?? Message::error(CommonMessageCode::E_OPERATION_FAILED, OperationMessageKey::OPERATION_EXCEPTION);
        }

        foreach ($result->messages() as $message) {
            if ($message->level() === MessageLevel::Success) {
                return $message;
            }
        }

        $message = $result->messages()[0] ?? null;
        if ($message instanceof Message) {
            return Message::success($message->translationKey(), $message->parameters());
        }

        return Message::success($fallbackSuccessKey);
    }
}
