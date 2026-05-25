<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ExtensionPackage;

final readonly class PackageLifecycleCleanupRunner implements PackageLifecycleCleanupRunnerInterface
{
    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function cleanup(ExtensionPackage $package): WorkflowResult
    {
        return WorkflowResult::success([
            'package' => $package->packageName(),
            'actions' => [],
        ], [
            'package' => $package->packageName(),
            'actions' => [],
        ], [
            Message::create(
                MessageCode::PACKAGE_LIFECYCLE_CLEANUP_COMPLETED,
                MessageKey::PACKAGE_LIFECYCLE_CLEANUP_COMPLETED,
                ['%package%' => $package->packageName()],
                ['package' => $package->packageName(), 'actions' => []],
                MessageLevel::Success,
            ),
        ]);
    }
}
