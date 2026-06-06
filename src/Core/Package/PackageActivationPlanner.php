<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Package\PackageMessageCode;
use App\Core\Package\PackageMessageKey;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ExtensionPackage;

final readonly class PackageActivationPlanner
{
    public function __construct(
        private PackageLifecycleStore $store,
        private PackageDependencyResolver $dependencyResolver,
    ) {
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function planActivation(string $packageName): WorkflowResult
    {
        $package = $this->store->package($packageName);

        if (null === $package) {
            return $this->packageNotFound($packageName);
        }

        if ($this->isActivationBlocked($package)) {
            return $this->statusBlocked($package);
        }

        $dependencies = $this->dependencyResolver->resolve($package);

        if (!$dependencies->isSuccess()) {
            return WorkflowResult::blocked($dependencies->issues(), [
                'package' => $packageName,
                'dependencies' => $dependencies->context()['dependencies'] ?? [],
            ]);
        }

        $packages = $dependencies->value()['packages'];
        $conflicts = $this->singleActiveConflictsFor($packages);
        $deactivations = $this->deactivationCascadeFor($conflicts, array_map(
            static fn (ExtensionPackage $candidate): string => $candidate->packageName(),
            $packages,
        ));
        $changes = [];

        foreach ($deactivations as $deactivation) {
            $changes[] = $this->change($deactivation, 'deactivated', ExtensionPackageStatus::Inactive);
        }

        foreach ($packages as $candidate) {
            if (ExtensionPackageStatus::Active !== $candidate->status()) {
                $changes[] = $this->change($candidate, 'activated', ExtensionPackageStatus::Active);
            }
        }

        return WorkflowResult::success([
            'package' => $packageName,
            'dependencies' => $dependencies->value()['dependencies'],
            'activate' => array_map(static fn (ExtensionPackage $candidate): string => $candidate->packageName(), $packages),
            'deactivate' => array_map(static fn (ExtensionPackage $candidate): string => $candidate->packageName(), $deactivations),
            'changes' => $changes,
            'asset_rebuild' => [] !== $changes,
        ], [
            'package' => $packageName,
            'dependencies' => $dependencies->value()['dependencies'],
            'changes' => $changes,
        ], $dependencies->messages());
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function planDeactivation(string $packageName): WorkflowResult
    {
        $package = $this->store->package($packageName);

        if (null === $package) {
            return $this->packageNotFound($packageName);
        }

        $packages = $this->deactivationCascadeFor([$package]);
        $changes = [];

        foreach ($packages as $candidate) {
            if (ExtensionPackageStatus::Active === $candidate->status()) {
                $changes[] = $this->change($candidate, 'deactivated', ExtensionPackageStatus::Inactive);
            }
        }

        return WorkflowResult::success([
            'package' => $packageName,
            'deactivate' => array_map(static fn (ExtensionPackage $candidate): string => $candidate->packageName(), $packages),
            'changes' => $changes,
            'asset_rebuild' => [] !== $changes,
        ], [
            'package' => $packageName,
            'changes' => $changes,
        ]);
    }

    /**
     * @return list<ExtensionPackage>
     */
    private function singleActiveConflicts(ExtensionPackage $package): array
    {
        $singleActiveScopes = array_map(
            static fn (PackageScope $scope): string => $scope->value,
            array_filter($package->scopes(), static fn (PackageScope $scope): bool => $scope->isSingleActive()),
        );

        if ([] === $singleActiveScopes) {
            return [];
        }

        $conflicts = [];

        foreach ($this->store->activePackages() as $candidate) {
            if (
                $candidate->packageName() !== $package->packageName()
                && $this->store->isManagedFilesystemPackage($candidate)
                && [] !== array_intersect($singleActiveScopes, $candidate->scopeValues())
            ) {
                $conflicts[] = $candidate;
            }
        }

        return $conflicts;
    }

    /**
     * @param list<ExtensionPackage> $packages
     *
     * @return list<ExtensionPackage>
     */
    private function singleActiveConflictsFor(array $packages): array
    {
        $activatingNames = array_fill_keys(array_map(static fn (ExtensionPackage $package): string => $package->packageName(), $packages), true);
        $conflicts = [];

        foreach ($packages as $package) {
            foreach ($this->singleActiveConflicts($package) as $conflict) {
                if (!isset($activatingNames[$conflict->packageName()])) {
                    $conflicts[$conflict->packageName()] = $conflict;
                }
            }
        }

        return array_values($conflicts);
    }

    /**
     * @param list<ExtensionPackage> $packages
     * @param list<string> $excludedPackageNames
     *
     * @return list<ExtensionPackage>
     */
    private function deactivationCascadeFor(array $packages, array $excludedPackageNames = []): array
    {
        $excluded = array_fill_keys($excludedPackageNames, true);
        $deactivations = [];

        foreach ($packages as $package) {
            foreach ($this->dependencyResolver->activeDependentsOf($package, [...array_keys($excluded), ...array_keys($deactivations)]) as $dependent) {
                if (isset($excluded[$dependent->packageName()]) || isset($deactivations[$dependent->packageName()])) {
                    continue;
                }

                $deactivations[$dependent->packageName()] = $dependent;
            }

            if (isset($excluded[$package->packageName()]) || isset($deactivations[$package->packageName()])) {
                continue;
            }

            $deactivations[$package->packageName()] = $package;
        }

        return array_values($deactivations);
    }

    private function isActivationBlocked(ExtensionPackage $package): bool
    {
        return in_array($package->status(), [
            ExtensionPackageStatus::Removed,
            ExtensionPackageStatus::Faulty,
        ], true);
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
                MessageLevel::Error,
            ),
        ]);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    private function statusBlocked(ExtensionPackage $package): WorkflowResult
    {
        return WorkflowResult::blocked([
            Message::create(
                PackageMessageCode::PACKAGE_LIFECYCLE_STATUS_BLOCKED,
                PackageMessageKey::PACKAGE_LIFECYCLE_STATUS_BLOCKED,
                ['%package%' => $package->packageName(), '%status%' => $package->status()->value],
                ['package' => $package->packageName(), 'status' => $package->status()->value],
                MessageLevel::Warning,
            ),
        ]);
    }

    /**
     * @return array{package: string, action: string, status: string}
     */
    private function change(ExtensionPackage $package, string $action, ExtensionPackageStatus $status): array
    {
        return [
            'package' => $package->packageName(),
            'action' => $action,
            'status' => $status->value,
        ];
    }
}
