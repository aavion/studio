<?php

declare(strict_types=1);

namespace App\Core\Message;

use App\Core\Workflow\WorkflowResult;

interface WorkflowResultMessageReporterInterface
{
    /**
     * @param WorkflowResult<mixed> $result
     * @param array<string, mixed> $operationContext
     *
     * @return WorkflowResult<mixed>
     */
    public function report(WorkflowResult $result, array $operationContext = []): WorkflowResult;
}
