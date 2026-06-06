<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Package\PackageMessageCode;
use App\Core\Package\PackageMessageKey;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ExtensionPackage;

final readonly class PackageLifecycleCleanupRunner implements PackageLifecycleCleanupRunnerInterface
{
    public function __construct(private Settings\PackageSettings $packageSettings)
    {
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function cleanup(ExtensionPackage $package): WorkflowResult
    {
        $deletedSettings = $this->packageSettings->removePackage($package->packageName());
        $actions = [];

        if ($deletedSettings > 0) {
            $actions[] = [
                'action' => 'delete_package_settings',
                'count' => $deletedSettings,
            ];
        }

        return WorkflowResult::success([
            'package' => $package->packageName(),
            'actions' => $actions,
        ], [
            'package' => $package->packageName(),
            'actions' => $actions,
        ], [
            Message::create(
                PackageMessageCode::PACKAGE_LIFECYCLE_CLEANUP_COMPLETED,
                PackageMessageKey::PACKAGE_LIFECYCLE_CLEANUP_COMPLETED,
                ['%package%' => $package->packageName()],
                ['package' => $package->packageName(), 'actions' => $actions],
                MessageLevel::Success,
            ),
        ]);
    }
}
