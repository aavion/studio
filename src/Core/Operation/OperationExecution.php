<?php

declare(strict_types=1);

namespace App\Core\Operation;

use App\Core\ActionLog\ActionLog;
use App\Core\Workflow\OperationResult;

final readonly class OperationExecution
{
    /**
     * @param OperationResult<mixed> $result
     */
    public function __construct(
        private ActionLog $actionLog,
        private OperationResult $result,
    ) {
    }

    public function actionLog(): ActionLog
    {
        return $this->actionLog;
    }

    /**
     * @return OperationResult<mixed>
     */
    public function result(): OperationResult
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
