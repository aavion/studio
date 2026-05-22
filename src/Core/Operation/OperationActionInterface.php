<?php

declare(strict_types=1);

namespace App\Core\Operation;

use App\Core\DryRun\DryRunAction;
use App\Core\Workflow\OperationResult;

interface OperationActionInterface
{
    public function type(): string;

    public function label(): string;

    public function dryRun(): DryRunAction;

    /**
     * @return OperationResult<mixed>
     */
    public function execute(): OperationResult;
}
