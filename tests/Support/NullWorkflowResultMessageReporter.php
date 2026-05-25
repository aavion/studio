<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Core\Message\WorkflowResultMessageReporterInterface;
use App\Core\Workflow\WorkflowResult;

final class NullWorkflowResultMessageReporter implements WorkflowResultMessageReporterInterface
{
    public function report(WorkflowResult $result, array $operationContext = []): WorkflowResult
    {
        return $result;
    }
}
