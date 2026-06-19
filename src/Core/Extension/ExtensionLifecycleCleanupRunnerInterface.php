<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Workflow\WorkflowResult;
use App\Entity\Extension;

interface ExtensionLifecycleCleanupRunnerInterface
{
    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function cleanup(Extension $extension): WorkflowResult;
}
