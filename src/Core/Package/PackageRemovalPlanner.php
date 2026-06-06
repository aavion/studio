<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ExtensionPackage;

final readonly class PackageRemovalPlanner
{
    public function __construct(
        private PackageLifecycleStore $store,
        private PackageActivator $activator,
    ) {
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function planRemoval(string $packageName): WorkflowResult
    {
        $package = $this->store->package($packageName);

        if (null === $package) {
            return $this->packageNotFound($packageName);
        }

        $changes = $this->plannedRemovalChanges($package);

        if (ExtensionPackageStatus::Active === $package->status()) {
            $plan = $this->activator->planDeactivation($packageName);

            if (!$plan->isSuccess()) {
                return WorkflowResult::failed($plan->issues(), [
                    'package' => $packageName,
                    'path' => $package->path(),
                    'plan_context' => $plan->context(),
                ], $plan->messages());
            }

            $planChanges = $plan->value()['changes'] ?? [];
            if (is_array($planChanges)) {
                $changes = array_values(array_filter(
                    $planChanges,
                    static fn (mixed $change): bool => is_array($change),
                ));
            }

            if (ExtensionPackageStatus::Removed !== $package->status()) {
                $changes[] = ['package' => $package->packageName(), 'action' => 'removed', 'status' => ExtensionPackageStatus::Removed->value];
            }
        }

        return WorkflowResult::success([
            'package' => $packageName,
            'changes' => $changes,
        ], [
            'package' => $packageName,
            'path' => $package->path(),
        ]);
    }

    /**
     * @return list<array{package: string, action: string, status: string}>
     */
    private function plannedRemovalChanges(ExtensionPackage $package): array
    {
        $changes = [];

        if (ExtensionPackageStatus::Active === $package->status()) {
            $changes[] = ['package' => $package->packageName(), 'action' => 'deactivated', 'status' => ExtensionPackageStatus::Inactive->value];
        }

        if (ExtensionPackageStatus::Removed !== $package->status()) {
            $changes[] = ['package' => $package->packageName(), 'action' => 'removed', 'status' => ExtensionPackageStatus::Removed->value];
        }

        return $changes;
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    private function packageNotFound(string $packageName): WorkflowResult
    {
        return WorkflowResult::invalid([
            Message::create(
                PackageMessageCode::PACKAGE_LIFECYCLE_PACKAGE_NOT_FOUND,
                PackageMessageKey::PACKAGE_LIFECYCLE_PACKAGE_NOT_FOUND,
                ['%package%' => $packageName],
                ['package' => $packageName],
                MessageLevel::Warning,
            ),
        ]);
    }
}
