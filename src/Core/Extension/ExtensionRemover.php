<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Message\WorkflowResultMessageReporterInterface;
use App\Core\Operation\OperationMessageCode;
use App\Core\Operation\OperationMessageKey;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Workflow\WorkflowResult;
use App\Entity\Extension;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final readonly class ExtensionRemover
{
    private ExtensionLifecycleStore $store;
    private ExtensionRemovalPlanner $removalPlanner;
    private ExtensionFilesystemRemover $filesystemRemover;
    private ExtensionPurger $purger;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ExtensionActivator $activator,
        private ExtensionLifecycleAssetRebuilderInterface $assetRebuilder,
        ExtensionLifecycleCleanupRunnerInterface $cleanupRunner,
        string $projectDir,
        private WorkflowResultMessageReporterInterface $messageReporter,
        ?ExtensionLifecycleStore $store = null,
        ?ExtensionRemovalPlanner $removalPlanner = null,
        ?ExtensionFilesystemRemover $filesystemRemover = null,
        ?ExtensionPurger $purger = null,
    ) {
        $this->store = $store ?? new ExtensionLifecycleStore($entityManager);
        $this->removalPlanner = $removalPlanner ?? new ExtensionRemovalPlanner($this->store, $activator);
        $this->filesystemRemover = $filesystemRemover ?? new ExtensionFilesystemRemover($projectDir, $this->store);
        $this->purger = $purger ?? new ExtensionPurger($entityManager, $this->store, $cleanupRunner);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function planRemoval(string $extensionName): WorkflowResult
    {
        return $this->report($this->removalPlanner->planRemoval($extensionName), 'extension.remove.plan', ['extension' => $extensionName]);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function remove(string $extensionName, string $environment, bool $rebuildAssets = true): WorkflowResult
    {
        $extension = $this->store->extension($extensionName);

        if (null === $extension) {
            return $this->report($this->extensionNotFound($extensionName), 'extension.remove', ['extension' => $extensionName, 'environment' => $environment]);
        }

        $messages = [];
        $changes = [];
        $previousStatuses = [$extensionName => $extension->status()];

        if (ExtensionStatus::Active === $extension->status()) {
            $plan = $this->activator->planDeactivation($extensionName);

            if (!$plan->isSuccess()) {
                return $this->report(WorkflowResult::failed($plan->issues(), [
                    'extension' => $extensionName,
                    'path' => $extension->path(),
                    'plan_context' => $plan->context(),
                ], $plan->messages()), 'extension.remove', ['extension' => $extensionName, 'environment' => $environment]);
            }

            $deactivationTargets = $this->extensionNameList($plan->value()['deactivate'] ?? []);
            $previousStatuses = $this->store->statusSnapshotsForNames($deactivationTargets);
            $deactivation = $this->activator->deactivate($extensionName, $environment, rebuildAssets: false);
            $messages = [...$messages, ...$deactivation->messages()];

            if (!$deactivation->isSuccess()) {
                return $this->report(WorkflowResult::failed($deactivation->issues(), [
                    'extension' => $extensionName,
                    'path' => $extension->path(),
                    'deactivation_context' => $deactivation->context(),
                ], $messages), 'extension.remove', ['extension' => $extensionName, 'environment' => $environment]);
            }

            $deactivationChanges = $deactivation->value()['changes'] ?? [];
            if (is_array($deactivationChanges)) {
                $changes = [...$changes, ...array_values(array_filter(
                    $deactivationChanges,
                    static fn (mixed $change): bool => is_array($change),
                ))];
            }
        }

        $filesystem = $this->filesystemRemover->remove($extension);
        $messages = [...$messages, ...$filesystem->messages()];

        if (!$filesystem->isSuccess()) {
            $rollbackMessages = $this->restoreExtensionStatuses($previousStatuses);

            return $this->report(WorkflowResult::failed($filesystem->issues(), [
                'extension' => $extensionName,
                'path' => $extension->path(),
                'changes' => $changes,
                'filesystem_context' => $filesystem->context(),
                'rolled_back' => [] === $rollbackMessages,
            ], [...$messages, ...$rollbackMessages]), 'extension.remove', ['extension' => $extensionName, 'environment' => $environment]);
        }

        if ($extension->markRemoved($this->removedMetadata($extension, $filesystem->context()))) {
            $changes[] = $this->change($extension, 'removed');
            $messages[] = Message::create(
                ExtensionMessageCode::EXTENSION_LIFECYCLE_REMOVED,
                ExtensionMessageKey::EXTENSION_LIFECYCLE_REMOVED,
                ['%extension%' => $extensionName],
                ['extension' => $extensionName, 'path' => $extension->path()],
                MessageLevel::Success,
            );
        }

        $this->entityManager->flush();

        if ([] !== $changes && $rebuildAssets) {
            $rebuild = $this->assetRebuilder->rebuild($environment);
            $messages = [...$messages, ...$rebuild->messages()];

            if (!$rebuild->isSuccess()) {
                return $this->report(WorkflowResult::failed($rebuild->issues(), [
                    'extension' => $extensionName,
                    'path' => $extension->path(),
                    'changes' => $changes,
                    'asset_rebuild' => true,
                    'rolled_back' => false,
                    'asset_rebuild_context' => $rebuild->context(),
                ], $messages), 'extension.remove', ['extension' => $extensionName, 'environment' => $environment]);
            }
        }

        return $this->report(WorkflowResult::success([
            'extension' => $extensionName,
            'path' => $extension->path(),
            'changes' => $changes,
            'asset_rebuild' => [] !== $changes && $rebuildAssets,
            'rolled_back' => false,
        ], [
            'extension' => $extensionName,
            'path' => $extension->path(),
            'changes' => $changes,
            'filesystem_context' => $filesystem->context(),
        ], $messages), 'extension.remove', ['extension' => $extensionName, 'environment' => $environment]);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function purge(string $extensionName): WorkflowResult
    {
        return $this->report($this->purger->purge($extensionName), 'extension.purge', ['extension' => $extensionName]);
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    private function extensionNameList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $extensionName): bool => is_string($extensionName) && '' !== trim($extensionName),
        ));
    }

    /**
     * @param array<string, ExtensionStatus> $statuses
     *
     * @return list<Message>
     */
    private function restoreExtensionStatuses(array $statuses): array
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
    private function removedMetadata(Extension $extension, array $filesystemContext): array
    {
        return [
            ...$extension->metadata(),
            'registry_state' => 'removed',
            'removed_path' => $extension->path(),
            'filesystem' => $filesystemContext,
        ];
    }

    /**
     * @return array{extension: string, action: string, status: string}
     */
    private function change(Extension $extension, string $action): array
    {
        return [
            'extension' => $extension->extensionName(),
            'action' => $action,
            'status' => $extension->status()->value,
        ];
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    private function extensionNotFound(string $extensionName): WorkflowResult
    {
        return WorkflowResult::invalid([
            Message::create(
                ExtensionMessageCode::EXTENSION_LIFECYCLE_EXTENSION_NOT_FOUND,
                ExtensionMessageKey::EXTENSION_LIFECYCLE_EXTENSION_NOT_FOUND,
                ['%extension%' => $extensionName],
                ['extension' => $extensionName],
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
