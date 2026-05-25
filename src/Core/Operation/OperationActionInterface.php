<?php

declare(strict_types=1);

namespace App\Core\Operation;

use App\Core\DryRun\DryRunAction;
use App\Core\Workflow\WorkflowResult;

interface OperationActionInterface
{
    public function type(): string;

    public function label(): string;

    public function dryRun(): DryRunAction;

    /**
     * @return WorkflowResult<mixed>
     */
    public function execute(): WorkflowResult;
}
