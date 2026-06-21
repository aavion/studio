<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Workflow\WorkflowResult;

interface ExtensionActivationContributionApplierInterface
{
    /**
     * @param list<\App\Entity\Extension> $extensions
     *
     * @return WorkflowResult<array<string, mixed>>
     */
    public function applyActivatedExtensions(array $extensions): WorkflowResult;
}
