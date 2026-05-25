<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Workflow\OperationResult;

interface PackageLifecycleAssetRebuilderInterface
{
    /**
     * @return OperationResult<mixed>
     */
    public function rebuild(string $environment): OperationResult;
}
