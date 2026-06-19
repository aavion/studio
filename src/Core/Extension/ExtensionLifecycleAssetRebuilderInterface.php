<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Workflow\WorkflowResult;

interface ExtensionLifecycleAssetRebuilderInterface
{
    /**
     * @return WorkflowResult<mixed>
     */
    public function rebuild(string $environment): WorkflowResult;
}
