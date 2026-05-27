<?php

declare(strict_types=1);

namespace App\Core\Operation;

use App\Core\ActionLog\ActionLog;
use App\Core\Workflow\WorkflowResult;

final readonly class OperationExecution
{
    /**
     * @param WorkflowResult<mixed> $result
     */
    public function __construct(
        private ActionLog $actionLog,
        private WorkflowResult $result,
    ) {
    }

    public function actionLog(): ActionLog
    {
        return $this->actionLog;
    }

    /**
     * @return WorkflowResult<mixed>
     */
    public function result(): WorkflowResult
    {
        return $this->result;
    }

    /**
     * @return array{action_log: array<string, mixed>, result: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'action_log' => $this->actionLog->toArray(),
            'result' => $this->result->toArray(),
        ];
    }
}
