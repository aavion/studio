<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use App\Core\Operation\ActionQueue;
use App\Core\Workflow\WorkflowResult;

interface LiveOperationQueueProviderInterface
{
    public function operation(): string;

    /**
     * @param array<string, mixed> $payload
     *
     * @return WorkflowResult<ActionQueue>
     */
    public function create(array $payload = []): WorkflowResult;
}
