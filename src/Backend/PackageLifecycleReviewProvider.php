<?php

declare(strict_types=1);

namespace App\Backend;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Package\ExtensionPackageStatus;
use App\Core\Package\PackageActivator;
use App\Core\Package\PackageRemover;
use App\Core\Workflow\WorkflowResult;

final readonly class PackageLifecycleReviewProvider
{
    public function __construct(
        private PackageAdminDetailProvider $detailProvider,
        private PackageActivator $activator,
        private PackageRemover $remover,
    ) {
    }

    public function review(string $packageName, string $action): array
    {
        $package = $this->detailProvider->package($packageName);
        $plan = null;

        if (null !== $package && !$package['immutable']) {
            $plan = match ($action) {
                PackageLifecycleAdmin::ACTION_ACTIVATE => $this->activator->planActivation($packageName)->toArray(),
                PackageLifecycleAdmin::ACTION_DEACTIVATE => $this->activator->planDeactivation($packageName)->toArray(),
                PackageLifecycleAdmin::ACTION_RESET_FAULT => $this->faultResetPlan($package)->toArray(),
                PackageLifecycleAdmin::ACTION_PURGE => $this->purgePlan($package)->toArray(),
                PackageLifecycleAdmin::ACTION_DELETE => $this->remover->planRemoval($packageName)->toArray(),
                default => null,
            };
        }

        return [
            'package' => $package,
            'action' => $action,
            'action_key' => str_replace('-', '_', $action),
            'plan' => $plan,
        ];
    }

    /**
     * @param array<string, mixed> $package
     *
     * @return WorkflowResult<array<string, mixed>>
     */
    private function faultResetPlan(array $package): WorkflowResult
    {
        return WorkflowResult::success([
            'package' => $package['package_name'],
            'changes' => [[
                'package' => $package['package_name'],
                'action' => 'fault_reset',
                'status' => ExtensionPackageStatus::Inactive->value,
            ]],
            'asset_rebuild' => false,
        ]);
    }

    /**
     * @param array<string, mixed> $package
     *
     * @return WorkflowResult<array<string, mixed>>
     */
    private function purgePlan(array $package): WorkflowResult
    {
        if (ExtensionPackageStatus::Removed->value !== ($package['status'] ?? null)) {
            return WorkflowResult::blocked([
                Message::create(
                    MessageCode::PACKAGE_LIFECYCLE_STATUS_BLOCKED,
                    MessageKey::PACKAGE_LIFECYCLE_STATUS_BLOCKED,
                    ['%package%' => $package['package_name'], '%status%' => (string) ($package['status'] ?? 'unknown')],
                    ['package' => $package['package_name'], 'status' => $package['status'] ?? null, 'action' => PackageLifecycleAdmin::ACTION_PURGE],
                    MessageLevel::Warning,
                ),
            ]);
        }

        return WorkflowResult::success([
            'package' => $package['package_name'],
            'changes' => [[
                'package' => $package['package_name'],
                'action' => 'purged',
                'status' => 'deleted',
            ]],
            'asset_rebuild' => false,
        ]);
    }
}
