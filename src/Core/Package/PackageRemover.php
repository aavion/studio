<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Operation\Filesystem\RemovePathAction;
use App\Core\Message\WorkflowResultMessageReporterInterface;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ExtensionPackage;
use App\Entity\SchedulerTask;
use App\Entity\SchedulerTaskRun;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final readonly class PackageRemover
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PackageActivator $activator,
        private PackageLifecycleAssetRebuilderInterface $assetRebuilder,
        private PackageLifecycleCleanupRunnerInterface $cleanupRunner,
        private string $projectDir,
        private WorkflowResultMessageReporterInterface $messageReporter,
    ) {
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function planRemoval(string $packageName): WorkflowResult
    {
        $package = $this->package($packageName);

        if (null === $package) {
            return $this->report($this->packageNotFound($packageName), 'package.remove.plan', ['package' => $packageName]);
        }

        $changes = $this->plannedRemovalChanges($package);

        if (ExtensionPackageStatus::Active === $package->status()) {
            $plan = $this->activator->planDeactivation($packageName);

            if (!$plan->isSuccess()) {
                return $this->report(WorkflowResult::failed($plan->issues(), [
                    'package' => $packageName,
                    'path' => $package->path(),
                    'plan_context' => $plan->context(),
                ], $plan->messages()), 'package.remove.plan', ['package' => $packageName]);
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

        return $this->report(WorkflowResult::success([
            'package' => $packageName,
            'changes' => $changes,
        ], [
            'package' => $packageName,
            'path' => $package->path(),
        ]), 'package.remove.plan', ['package' => $packageName]);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function remove(string $packageName, string $environment, bool $rebuildAssets = true): WorkflowResult
    {
        $package = $this->package($packageName);

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
            $previousStatuses = $this->statusSnapshots($deactivationTargets);
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

        $filesystem = $this->removeFilesystemPackage($package);
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
                MessageCode::PACKAGE_LIFECYCLE_REMOVED,
                MessageKey::PACKAGE_LIFECYCLE_REMOVED,
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
        $package = $this->package($packageName);

        if (null === $package) {
            return $this->report($this->packageNotFound($packageName), 'package.purge', ['package' => $packageName]);
        }

        if (ExtensionPackageStatus::Removed !== $package->status()) {
            return $this->report($this->statusBlocked($package, 'package.purge'), 'package.purge', ['package' => $packageName]);
        }

        $cleanup = $this->cleanupRunner->cleanup($package);

        if (!$cleanup->isSuccess()) {
            return $this->report(WorkflowResult::failed($cleanup->issues(), [
                'package' => $packageName,
                'cleanup_context' => $cleanup->context(),
            ], $cleanup->messages()), 'package.purge', ['package' => $packageName]);
        }

        foreach ($this->entityManager->getRepository(SchedulerTask::class)->findBy(['source' => $packageName]) as $task) {
            if ($task instanceof SchedulerTask) {
                foreach ($this->entityManager->getRepository(SchedulerTaskRun::class)->findBy(['task' => $task]) as $run) {
                    $this->entityManager->remove($run);
                }
                $this->entityManager->remove($task);
            }
        }
        $this->entityManager->remove($package);
        $this->entityManager->flush();

        return $this->report(WorkflowResult::success([
            'package' => $packageName,
            'changes' => [[
                'package' => $packageName,
                'action' => 'purged',
                'status' => 'deleted',
            ]],
        ], [
            'package' => $packageName,
            'cleanup_context' => $cleanup->context(),
        ], [
            ...$cleanup->messages(),
            Message::create(
                MessageCode::PACKAGE_LIFECYCLE_PURGED,
                MessageKey::PACKAGE_LIFECYCLE_PURGED,
                ['%package%' => $packageName],
                ['package' => $packageName],
                MessageLevel::Success,
            ),
        ]), 'package.purge', ['package' => $packageName]);
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
     * @param list<string> $packageNames
     *
     * @return array<string, ExtensionPackageStatus>
     */
    private function statusSnapshots(array $packageNames): array
    {
        $snapshots = [];

        foreach (array_unique($packageNames) as $packageName) {
            $package = $this->package($packageName);

            if ($package instanceof ExtensionPackage) {
                $snapshots[$packageName] = $package->status();
            }
        }

        return $snapshots;
    }

    /**
     * @param array<string, ExtensionPackageStatus> $statuses
     *
     * @return list<Message>
     */
    private function restorePackageStatuses(array $statuses): array
    {
        try {
            foreach ($statuses as $packageName => $status) {
                $package = $this->package($packageName);

                if ($package instanceof ExtensionPackage) {
                    $package->restoreStatus($status);
                }
            }

            $this->entityManager->flush();
        } catch (Throwable $error) {
            return [
                Message::exception(
                    MessageCode::OPERATION_EXCEPTION,
                    MessageKey::OPERATION_EXCEPTION,
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

    private function package(string $packageName): ?ExtensionPackage
    {
        $package = $this->entityManager->getRepository(ExtensionPackage::class)->findOneBy([
            'packageName' => $packageName,
        ]);

        return $package instanceof ExtensionPackage ? $package : null;
    }

    /**
     * @return WorkflowResult<array{path: string, removed: bool}>
     */
    private function removeFilesystemPackage(ExtensionPackage $package): WorkflowResult
    {
        if (!str_starts_with($package->path(), 'packages/')) {
            return WorkflowResult::blocked([
                Message::create(
                    MessageCode::PACKAGE_LIFECYCLE_STATUS_BLOCKED,
                    MessageKey::PACKAGE_LIFECYCLE_STATUS_BLOCKED,
                    ['%package%' => $package->packageName(), '%status%' => $package->status()->value],
                    ['package' => $package->packageName(), 'path' => $package->path(), 'reason' => 'not_filesystem_package'],
                    MessageLevel::Warning,
                ),
            ]);
        }

        try {
            return (new RemovePathAction($this->projectDir, $package->path()))->execute();
        } catch (Throwable $error) {
            return WorkflowResult::failed([
                Message::exception(
                    MessageCode::OPERATION_EXCEPTION,
                    MessageKey::OPERATION_EXCEPTION,
                    context: [
                        'package' => $package->packageName(),
                        'path' => $package->path(),
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                    ],
                ),
            ]);
        }
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
                MessageCode::PACKAGE_LIFECYCLE_PACKAGE_NOT_FOUND,
                MessageKey::PACKAGE_LIFECYCLE_PACKAGE_NOT_FOUND,
                ['%package%' => $packageName],
                ['package' => $packageName],
                MessageLevel::Warning,
            ),
        ]);
    }

    private function statusBlocked(ExtensionPackage $package, string $operation): WorkflowResult
    {
        return WorkflowResult::blocked([
            Message::create(
                MessageCode::PACKAGE_LIFECYCLE_STATUS_BLOCKED,
                MessageKey::PACKAGE_LIFECYCLE_STATUS_BLOCKED,
                ['%package%' => $package->packageName(), '%status%' => $package->status()->value],
                ['package' => $package->packageName(), 'status' => $package->status()->value, 'operation' => $operation],
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
