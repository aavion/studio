<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Workflow\OperationResult;
use App\Entity\ExtensionPackage;

interface PackageLifecycleCleanupRunnerInterface
{
    /**
     * @return OperationResult<array<string, mixed>>
     */
    public function cleanup(ExtensionPackage $package): OperationResult;
}
