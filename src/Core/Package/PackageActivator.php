<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Workflow\OperationIssue;
use App\Core\Workflow\OperationResult;
use App\Entity\ExtensionPackage;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PackageActivator
{
    private PackageDependencyResolver $dependencyResolver;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PackageLifecycleAssetRebuilderInterface $assetRebuilder,
        ?PackageDependencyResolver $dependencyResolver = null,
    ) {
        $this->dependencyResolver = $dependencyResolver ?? new PackageDependencyResolver($entityManager);
    }

    /**
     * @return OperationResult<array<string, mixed>>
     */
    public function planActivation(string $packageName): OperationResult
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
            return OperationResult::blocked($dependencies->issues(), [
                'package' => $packageName,
                'dependencies' => $dependencies->context()['dependencies'] ?? [],
            ]);
        }

        $packages = $dependencies->value()['packages'];
        $conflicts = $this->singleActiveConflictsFor($packages);
        $changes = [];

        foreach ($conflicts as $conflict) {
            $changes[] = [
                'package' => $conflict->packageName(),
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

        return OperationResult::success([
            'package' => $packageName,
            'dependencies' => $dependencies->value()['dependencies'],
            'activate' => array_map(static fn (ExtensionPackage $candidate): string => $candidate->packageName(), $packages),
            'deactivate' => array_map(static fn (ExtensionPackage $candidate): string => $candidate->packageName(), $conflicts),
            'changes' => $changes,
            'asset_rebuild' => [] !== $changes,
        ], [
            'package' => $packageName,
            'dependencies' => $dependencies->value()['dependencies'],
            'changes' => $changes,
        ]);
    }

    /**
     * @return OperationResult<array<string, mixed>>
     */
    public function activate(string $packageName, string $environment, bool $rebuildAssets = true): OperationResult
    {
        $plan = $this->planActivation($packageName);

        if (!$plan->isSuccess()) {
            return $plan;
        }

        $packages = $this->packagesByName($plan->value()['activate']);
        $conflicts = $this->packagesByName($plan->value()['deactivate']);
        $snapshots = $this->statusSnapshots([...$packages, ...$conflicts]);
        $changes = [];
        $messages = [];

        foreach ($conflicts as $conflict) {
            if ($conflict->deactivate()) {
                $changes[] = $this->change($conflict, 'deactivated');
                $messages[] = $this->deactivatedMessage($conflict);
            }
        }

        foreach ($packages as $package) {
            if ($package->activate()) {
                $changes[] = $this->change($package, 'activated');
                $messages[] = Message::info(
                    MessageCode::PACKAGE_LIFECYCLE_ACTIVATED,
                    MessageKey::PACKAGE_LIFECYCLE_ACTIVATED,
                    ['%package%' => $package->packageName()],
                    ['package' => $package->packageName()],
                );
            }
        }

        return $this->finalize($snapshots, $changes, $messages, $environment, $rebuildAssets);
    }

    /**
     * @return OperationResult<array<string, mixed>>
     */
    public function deactivate(string $packageName, string $environment, bool $rebuildAssets = true): OperationResult
    {
        $package = $this->package($packageName);

        if (null === $package) {
            return $this->packageNotFound($packageName);
        }

        $snapshots = $this->statusSnapshots([$package]);
        $changes = [];
        $messages = [];

        if (ExtensionPackageStatus::Active === $package->status() && $package->deactivate()) {
            $changes[] = $this->change($package, 'deactivated');
            $messages[] = $this->deactivatedMessage($package);
        }

        return $this->finalize($snapshots, $changes, $messages, $environment, $rebuildAssets);
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
     * @return OperationResult<array<string, mixed>>
     */
    private function finalize(array $snapshots, array $changes, array $messages, string $environment, bool $rebuildAssets): OperationResult
    {
        if ([] === $changes) {
            return OperationResult::success([
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
            return OperationResult::success([
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
            return OperationResult::success([
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

        return OperationResult::failed($rebuild->issues(), [
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
     * @return OperationResult<array<string, mixed>>
     */
    private function packageNotFound(string $packageName): OperationResult
    {
        return OperationResult::invalid([
            OperationIssue::create(
                MessageCode::PACKAGE_LIFECYCLE_PACKAGE_NOT_FOUND,
                MessageKey::PACKAGE_LIFECYCLE_PACKAGE_NOT_FOUND,
                ['%package%' => $packageName],
                ['package' => $packageName],
                MessageLevel::Warning,
            ),
        ]);
    }

    /**
     * @return OperationResult<array<string, mixed>>
     */
    private function statusBlocked(ExtensionPackage $package): OperationResult
    {
        return OperationResult::blocked([
            OperationIssue::create(
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
        return Message::info(
            MessageCode::PACKAGE_LIFECYCLE_DEACTIVATED,
            MessageKey::PACKAGE_LIFECYCLE_DEACTIVATED,
            ['%package%' => $package->packageName()],
            ['package' => $package->packageName()],
        );
    }
}
