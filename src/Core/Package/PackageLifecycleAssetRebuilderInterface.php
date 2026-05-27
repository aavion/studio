<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Workflow\WorkflowResult;

interface PackageLifecycleAssetRebuilderInterface
{
    /**
     * @return WorkflowResult<mixed>
     */
    public function rebuild(string $environment): WorkflowResult;
}
