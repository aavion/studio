<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\AdminAcl\AdminFeatureOverrideStore;
use App\Core\AdminAcl\AdminFeatureRegistry;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Package\PackageMessageCode;
use App\Core\Package\PackageMessageKey;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ExtensionPackage;

final readonly class PackageLifecycleCleanupRunner implements PackageLifecycleCleanupRunnerInterface
{
    public function __construct(
        private Settings\PackageSettings $packageSettings,
        private AdminFeatureOverrideStore $adminFeatureOverrideStore,
        private ?AdminFeatureRegistry $adminFeatureRegistry = null,
    ) {
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

        if ($this->removePackageAclOverride($package->packageName())) {
            $actions[] = [
                'action' => 'delete_package_acl_override',
                'count' => 1,
            ];
        }

        $this->adminFeatureRegistry?->resetCache();

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

    private function removePackageAclOverride(string $packageName): bool
    {
        $feature = 'admin.settings.packages.'.$packageName;
        $overrides = $this->adminFeatureOverrideStore->overrides();

        if (!isset($overrides[$feature])) {
            return false;
        }

        unset($overrides[$feature]);

        return $this->adminFeatureOverrideStore->save($overrides, 'package_lifecycle_cleanup');
    }
}
