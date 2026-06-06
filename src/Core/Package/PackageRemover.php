<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Message\WorkflowResultMessageReporterInterface;
use App\Core\Operation\OperationMessageCode;
use App\Core\Operation\OperationMessageKey;
use App\Core\Package\PackageMessageCode;
use App\Core\Package\PackageMessageKey;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ExtensionPackage;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final readonly class PackageRemover
{
    private PackageLifecycleStore $store;
    private PackageRemovalPlanner $removalPlanner;
    private PackageFilesystemRemover $filesystemRemover;
    private PackagePurger $purger;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PackageActivator $activator,
        private PackageLifecycleAssetRebuilderInterface $assetRebuilder,
        PackageLifecycleCleanupRunnerInterface $cleanupRunner,
        string $projectDir,
        private WorkflowResultMessageReporterInterface $messageReporter,
        ?PackageLifecycleStore $store = null,
        ?PackageRemovalPlanner $removalPlanner = null,
        ?PackageFilesystemRemover $filesystemRemover = null,
        ?PackagePurger $purger = null,
    ) {
        $this->store = $store ?? new PackageLifecycleStore($entityManager);
        $this->removalPlanner = $removalPlanner ?? new PackageRemovalPlanner($this->store, $activator);
        $this->filesystemRemover = $filesystemRemover ?? new PackageFilesystemRemover($projectDir, $this->store);
        $this->purger = $purger ?? new PackagePurger($entityManager, $this->store, $cleanupRunner);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function planRemoval(string $packageName): WorkflowResult
    {
        return $this->report($this->removalPlanner->planRemoval($packageName), 'package.remove.plan', ['package' => $packageName]);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function remove(string $packageName, string $environment, bool $rebuildAssets = true): WorkflowResult
    {
        $package = $this->store->package($packageName);

        if (null === $package) {
            return $this->report($this->packageNotFound($packageName), 'package.remove', ['package' => $packageName, 'environment' => $environment]);
        }

        $messages = [];
        $changes = [];
        $previousStatuses = [$packageName => $package->status()];

        if (ExtensionPackageStatus::Active === $package->status()) {
            $plan = $this->activator->planDeactivation($packageName);

            if (!$plan->isSuccess()) {
                return $this->report(WorkflowResult::failed($plan->issues(), [
                    'package' => $packageName,
                    'path' => $package->path(),
                    'plan_context' => $plan->context(),
                ], $plan->messages()), 'package.remove', ['package' => $packageName, 'environment' => $environment]);
            }

            $deactivationTargets = $this->packageNameList($plan->value()['deactivate'] ?? []);
            $previousStatuses = $this->store->statusSnapshotsForNames($deactivationTargets);
            $deactivation = $this->activator->deactivate($packageName, $environment, rebuildAssets: false);
            $messages = [...$messages, ...$deactivation->messages()];

            if (!$deactivation->isSuccess()) {
                return $this->report(WorkflowResult::failed($deactivation->issues(), [
                    'package' => $packageName,
                    'path' => $package->path(),
                    'deactivation_context' => $deactivation->context(),
                ], $messages), 'package.remove', ['package' => $packageName, 'environment' => $environment]);
            }

            $deactivationChanges = $deactivation->value()['changes'] ?? [];
            if (is_array($deactivationChanges)) {
                $changes = [...$changes, ...array_values(array_filter(
                    $deactivationChanges,
                    static fn (mixed $change): bool => is_array($change),
                ))];
            }
        }

        $filesystem = $this->filesystemRemover->remove($package);
        $messages = [...$messages, ...$filesystem->messages()];

        if (!$filesystem->isSuccess()) {
            $rollbackMessages = $this->restorePackageStatuses($previousStatuses);

            return $this->report(WorkflowResult::failed($filesystem->issues(), [
                'package' => $packageName,
                'path' => $package->path(),
                'changes' => $changes,
                'filesystem_context' => $filesystem->context(),
                'rolled_back' => [] === $rollbackMessages,
            ], [...$messages, ...$rollbackMessages]), 'package.remove', ['package' => $packageName, 'environment' => $environment]);
        }

        if ($package->markRemoved($this->removedMetadata($package, $filesystem->context()))) {
            $changes[] = $this->change($package, 'removed');
            $messages[] = Message::create(
                PackageMessageCode::PACKAGE_LIFECYCLE_REMOVED,
                PackageMessageKey::PACKAGE_LIFECYCLE_REMOVED,
                ['%package%' => $packageName],
                ['package' => $packageName, 'path' => $package->path()],
                MessageLevel::Success,
            );
        }

        $this->entityManager->flush();

        if ([] !== $changes && $rebuildAssets) {
            $rebuild = $this->assetRebuilder->rebuild($environment);
            $messages = [...$messages, ...$rebuild->messages()];

            if (!$rebuild->isSuccess()) {
                return $this->report(WorkflowResult::failed($rebuild->issues(), [
                    'package' => $packageName,
                    'path' => $package->path(),
                    'changes' => $changes,
                    'asset_rebuild' => true,
                    'rolled_back' => false,
                    'asset_rebuild_context' => $rebuild->context(),
                ], $messages), 'package.remove', ['package' => $packageName, 'environment' => $environment]);
            }
        }

        return $this->report(WorkflowResult::success([
            'package' => $packageName,
            'path' => $package->path(),
            'changes' => $changes,
            'asset_rebuild' => [] !== $changes && $rebuildAssets,
            'rolled_back' => false,
        ], [
            'package' => $packageName,
            'path' => $package->path(),
            'changes' => $changes,
            'filesystem_context' => $filesystem->context(),
        ], $messages), 'package.remove', ['package' => $packageName, 'environment' => $environment]);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function purge(string $packageName): WorkflowResult
    {
        return $this->report($this->purger->purge($packageName), 'package.purge', ['package' => $packageName]);
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    private function packageNameList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $packageName): bool => is_string($packageName) && '' !== trim($packageName),
        ));
    }

    /**
     * @param array<string, ExtensionPackageStatus> $statuses
     *
     * @return list<Message>
     */
    private function restorePackageStatuses(array $statuses): array
    {
        try {
            $this->store->restoreStatuses($statuses);
            $this->entityManager->flush();
        } catch (Throwable $error) {
            return [
                Message::exception(
                    OperationMessageCode::OPERATION_EXCEPTION,
                    OperationMessageKey::OPERATION_EXCEPTION,
                    context: [
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                        'rollback' => true,
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $filesystemContext
     *
     * @return array<string, mixed>
     */
    private function removedMetadata(ExtensionPackage $package, array $filesystemContext): array
    {
        return [
            ...$package->metadata(),
            'registry_state' => 'removed',
            'removed_path' => $package->path(),
            'filesystem' => $filesystemContext,
        ];
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

    private function report(WorkflowResult $result, string $operation, array $context = []): WorkflowResult
    {
        return $this->messageReporter->report($result, [
            ...$context,
            'operation' => $operation,
        ]);
    }
}
