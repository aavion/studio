<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Message\WorkflowResultMessageReporterInterface;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ExtensionPackage;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PackageActivator
{
    private PackageDependencyResolver $dependencyResolver;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PackageLifecycleAssetRebuilderInterface $assetRebuilder,
        private WorkflowResultMessageReporterInterface $messageReporter,
        ?PackageDependencyResolver $dependencyResolver = null,
    ) {
        $this->dependencyResolver = $dependencyResolver ?? new PackageDependencyResolver($entityManager);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function planActivation(string $packageName): WorkflowResult
    {
        return $this->report($this->doPlanActivation($packageName), 'package.activate.plan', ['package' => $packageName]);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function planDeactivation(string $packageName): WorkflowResult
    {
        return $this->report($this->doPlanDeactivation($packageName), 'package.deactivate.plan', ['package' => $packageName]);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    private function doPlanActivation(string $packageName): WorkflowResult
    {
        $package = $this->package($packageName);

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
            $changes[] = [
                'package' => $deactivation->packageName(),
                'action' => 'deactivated',
                'status' => ExtensionPackageStatus::Inactive->value,
            ];
        }

        foreach ($packages as $candidate) {
            if (ExtensionPackageStatus::Active !== $candidate->status()) {
                $changes[] = [
                    'package' => $candidate->packageName(),
                    'action' => 'activated',
                    'status' => ExtensionPackageStatus::Active->value,
                ];
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
    public function activate(string $packageName, string $environment, bool $rebuildAssets = true): WorkflowResult
    {
        $plan = $this->doPlanActivation($packageName);

        if (!$plan->isSuccess()) {
            return $this->report($plan, 'package.activate', ['package' => $packageName, 'environment' => $environment]);
        }

        $packages = $this->packagesByName($plan->value()['activate']);
        $conflicts = $this->packagesByName($plan->value()['deactivate']);
        $snapshots = $this->statusSnapshots([...$packages, ...$conflicts]);
        $changes = [];
        $messages = $plan->messages();

        foreach ($conflicts as $conflict) {
            if ($conflict->deactivate()) {
                $changes[] = $this->change($conflict, 'deactivated');
                $messages[] = $this->deactivatedMessage($conflict);
            }
        }

        foreach ($packages as $package) {
            if ($package->activate()) {
                $changes[] = $this->change($package, 'activated');
                $messages[] = Message::create(
                    MessageCode::PACKAGE_LIFECYCLE_ACTIVATED,
                    MessageKey::PACKAGE_LIFECYCLE_ACTIVATED,
                    ['%package%' => $package->packageName()],
                    ['package' => $package->packageName()],
                    MessageLevel::Success,
                );
            }
        }

        return $this->report(
            $this->finalize($snapshots, $changes, $messages, $environment, $rebuildAssets),
            'package.activate',
            ['package' => $packageName, 'environment' => $environment],
        );
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function deactivate(string $packageName, string $environment, bool $rebuildAssets = true): WorkflowResult
    {
        $plan = $this->doPlanDeactivation($packageName);

        if (!$plan->isSuccess()) {
            return $this->report($plan, 'package.deactivate', ['package' => $packageName, 'environment' => $environment]);
        }

        $packages = $this->packagesByName($plan->value()['deactivate']);
        $snapshots = $this->statusSnapshots($packages);
        $changes = [];
        $messages = [];

        foreach ($packages as $package) {
            if (ExtensionPackageStatus::Active === $package->status() && $package->deactivate()) {
                $changes[] = $this->change($package, 'deactivated');
                $messages[] = $this->deactivatedMessage($package);
            }
        }

        return $this->report(
            $this->finalize($snapshots, $changes, $messages, $environment, $rebuildAssets),
            'package.deactivate',
            ['package' => $packageName, 'environment' => $environment],
        );
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    private function doPlanDeactivation(string $packageName): WorkflowResult
    {
        $package = $this->package($packageName);

        if (null === $package) {
            return $this->packageNotFound($packageName);
        }

        $packages = $this->deactivationCascadeFor([$package]);
        $changes = [];

        foreach ($packages as $candidate) {
            if (ExtensionPackageStatus::Active !== $candidate->status()) {
                continue;
            }

            $changes[] = [
                'package' => $candidate->packageName(),
                'action' => 'deactivated',
                'status' => ExtensionPackageStatus::Inactive->value,
            ];
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

    private function package(string $packageName): ?ExtensionPackage
    {
        $package = $this->entityManager->getRepository(ExtensionPackage::class)->findOneBy([
            'packageName' => $packageName,
        ]);

        return $package instanceof ExtensionPackage ? $package : null;
    }

    private function isActivationBlocked(ExtensionPackage $package): bool
    {
        return in_array($package->status(), [
            ExtensionPackageStatus::Removed,
            ExtensionPackageStatus::Faulty,
        ], true);
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

        foreach ($this->entityManager->getRepository(ExtensionPackage::class)->findBy(['status' => ExtensionPackageStatus::Active]) as $candidate) {
            if (
                !$candidate instanceof ExtensionPackage
                || $candidate->packageName() === $package->packageName()
                || !$this->isManagedFilesystemPackage($candidate)
            ) {
                continue;
            }

            if ([] !== array_intersect($singleActiveScopes, $candidate->scopeValues())) {
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

            if (!isset($excluded[$package->packageName()]) && !isset($deactivations[$package->packageName()])) {
                $deactivations[$package->packageName()] = $package;
            }
        }

        return array_values($deactivations);
    }

    /**
     * @param list<string> $packageNames
     *
     * @return list<ExtensionPackage>
     */
    private function packagesByName(array $packageNames): array
    {
        $packages = [];

        foreach ($packageNames as $packageName) {
            $package = $this->package($packageName);

            if (null !== $package) {
                $packages[] = $package;
            }
        }

        return $packages;
    }

    private function isManagedFilesystemPackage(ExtensionPackage $package): bool
    {
        return str_starts_with($package->path(), 'packages/');
    }

    /**
     * @param list<ExtensionPackage> $packages
     *
     * @return array<string, ExtensionPackageStatus>
     */
    private function statusSnapshots(array $packages): array
    {
        $snapshots = [];

        foreach ($packages as $package) {
            $snapshots[$package->packageName()] = $package->status();
        }

        return $snapshots;
    }

    /**
     * @param array<string, ExtensionPackageStatus> $snapshots
     * @param list<array{package: string, action: string, status: string}> $changes
     * @param list<Message> $messages
     *
     * @return WorkflowResult<array<string, mixed>>
     */
    private function finalize(array $snapshots, array $changes, array $messages, string $environment, bool $rebuildAssets): WorkflowResult
    {
        if ([] === $changes) {
            return WorkflowResult::success([
                'changes' => [],
                'asset_rebuild' => false,
                'rolled_back' => false,
            ], [
                'changes' => [],
                'asset_rebuild' => false,
                'rolled_back' => false,
            ], $messages);
        }

        $this->entityManager->flush();

        if (!$rebuildAssets) {
            return WorkflowResult::success([
                'changes' => $changes,
                'asset_rebuild' => false,
                'rolled_back' => false,
            ], [
                'changes' => $changes,
                'asset_rebuild' => false,
                'rolled_back' => false,
            ], $messages);
        }

        $rebuild = $this->assetRebuilder->rebuild($environment);

        if ($rebuild->isSuccess()) {
            return WorkflowResult::success([
                'changes' => $changes,
                'asset_rebuild' => true,
                'rolled_back' => false,
            ], [
                'changes' => $changes,
                'asset_rebuild' => true,
                'rolled_back' => false,
                'asset_rebuild_context' => $rebuild->context(),
            ], [...$messages, ...$rebuild->messages()]);
        }

        $this->restoreStatuses($snapshots);

        return WorkflowResult::failed($rebuild->issues(), [
            'changes' => $changes,
            'asset_rebuild' => true,
            'rolled_back' => true,
            'asset_rebuild_context' => $rebuild->context(),
        ], [
            ...$messages,
            ...$rebuild->messages(),
            Message::warning(
                MessageCode::PACKAGE_LIFECYCLE_ROLLED_BACK,
                MessageKey::PACKAGE_LIFECYCLE_ROLLED_BACK,
                ['%count%' => count($snapshots)],
                ['package_count' => count($snapshots), 'packages' => array_keys($snapshots)],
            ),
        ]);
    }

    /**
     * @param array<string, ExtensionPackageStatus> $snapshots
     */
    private function restoreStatuses(array $snapshots): void
    {
        foreach ($snapshots as $packageName => $status) {
            $package = $this->package($packageName);

            if (null !== $package) {
                $package->restoreStatus($status);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    private function packageNotFound(string $packageName): WorkflowResult
    {
        return WorkflowResult::invalid([
            Message::create(
                MessageCode::PACKAGE_LIFECYCLE_PACKAGE_NOT_FOUND,
                MessageKey::PACKAGE_LIFECYCLE_PACKAGE_NOT_FOUND,
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
                MessageCode::PACKAGE_LIFECYCLE_STATUS_BLOCKED,
                MessageKey::PACKAGE_LIFECYCLE_STATUS_BLOCKED,
                ['%package%' => $package->packageName(), '%status%' => $package->status()->value],
                ['package' => $package->packageName(), 'status' => $package->status()->value],
                MessageLevel::Warning,
            ),
        ]);
    }

    /**
     * @return array{package: string, action: string, status: string}
     */
    private function change(ExtensionPackage $package, string $action): array
    {
        return [
            'package' => $package->packageName(),
            'action' => $action,
            'status' => $package->status()->value,
        ];
    }

    private function deactivatedMessage(ExtensionPackage $package): Message
    {
        return Message::create(
            MessageCode::PACKAGE_LIFECYCLE_DEACTIVATED,
            MessageKey::PACKAGE_LIFECYCLE_DEACTIVATED,
            ['%package%' => $package->packageName()],
            ['package' => $package->packageName()],
            MessageLevel::Success,
        );
    }

    private function report(WorkflowResult $result, string $operation, array $context = []): WorkflowResult
    {
        return $this->messageReporter->report($result, [
            ...$context,
            'operation' => $operation,
        ]);
    }
}
