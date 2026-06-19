<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Message\WorkflowResultMessageReporterInterface;
use App\Core\Extension\Content\ExtensionContentSchemaImpact;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Workflow\WorkflowResult;
use App\Entity\Extension;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ExtensionActivator
{
    private ExtensionLifecycleStore $store;
    private ExtensionActivationPlanner $planner;
    private ExtensionLifecycleFinalizer $finalizer;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ExtensionLifecycleAssetRebuilderInterface $assetRebuilder,
        private WorkflowResultMessageReporterInterface $messageReporter,
        ?ExtensionDependencyResolver $dependencyResolver = null,
        ?ExtensionLifecycleStore $store = null,
        ?ExtensionActivationPlanner $planner = null,
        ?ExtensionLifecycleFinalizer $finalizer = null,
        private ?ExtensionActivationContributionApplierInterface $activationContributionApplier = null,
        private ?ExtensionContentSchemaImpact $contentSchemaImpact = null,
    ) {
        $this->store = $store ?? new ExtensionLifecycleStore($this->entityManager);
        $dependencyResolver ??= new ExtensionDependencyResolver($this->entityManager);
        $this->planner = $planner ?? new ExtensionActivationPlanner($this->store, $dependencyResolver, $this->contentSchemaImpact);
        $this->finalizer = $finalizer ?? new ExtensionLifecycleFinalizer($this->entityManager, $this->store, $this->assetRebuilder);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function planActivation(string $extensionName): WorkflowResult
    {
        return $this->report($this->planner->planActivation($extensionName), 'extension.activate.plan', ['extension' => $extensionName]);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function planDeactivation(string $extensionName): WorkflowResult
    {
        return $this->report($this->planner->planDeactivation($extensionName), 'extension.deactivate.plan', ['extension' => $extensionName]);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function activate(string $extensionName, string $environment, bool $rebuildAssets = true): WorkflowResult
    {
        $plan = $this->planner->planActivation($extensionName);

        if (!$plan->isSuccess()) {
            return $this->report($plan, 'extension.activate', ['extension' => $extensionName, 'environment' => $environment]);
        }

        $extensions = $this->store->extensionsByName($plan->value()['activate']);
        $conflicts = $this->store->extensionsByName($plan->value()['deactivate']);
        $snapshots = $this->store->statusSnapshots([...$extensions, ...$conflicts]);
        $changes = [];
        $messages = $plan->messages();

        foreach ($conflicts as $conflict) {
            if ($conflict->deactivate()) {
                $changes[] = $this->change($conflict, 'deactivated');
                $messages[] = $this->deactivatedMessage($conflict);
            }
        }

        foreach ($extensions as $extension) {
            if ($extension->activate()) {
                $changes[] = $this->change($extension, 'activated');
                $messages[] = Message::create(
                    ExtensionMessageCode::EXTENSION_LIFECYCLE_ACTIVATED,
                    ExtensionMessageKey::EXTENSION_LIFECYCLE_ACTIVATED,
                    ['%extension%' => $extension->extensionName()],
                    ['extension' => $extension->extensionName()],
                    MessageLevel::Success,
                );
            }
        }

        $messages = [...$messages, ...$this->archiveContentForDeactivatedExtensions($conflicts)];

        $finalized = $this->finalizer->finalize($snapshots, $changes, $messages, $environment, $rebuildAssets);
        if (!$finalized->isSuccess() || null === $this->activationContributionApplier) {
            return $this->report($finalized, 'extension.activate', ['extension' => $extensionName, 'environment' => $environment]);
        }

        $contributions = $this->activationContributionApplier->applyActivatedExtensions($extensions);
        if ($contributions->isSuccess()) {
            return $this->report(WorkflowResult::success([
                ...$finalized->value(),
                'contributions' => $contributions->value(),
            ], [
                ...$finalized->context(),
                'contribution_context' => $contributions->context(),
            ], [
                ...$finalized->messages(),
                ...$contributions->messages(),
            ]), 'extension.activate', ['extension' => $extensionName, 'environment' => $environment]);
        }

        return $this->report(
            $this->rollbackContributionFailure($extensionName, $environment, $snapshots, $changes, $rebuildAssets, $finalized, $contributions),
            'extension.activate',
            ['extension' => $extensionName, 'environment' => $environment],
        );
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function deactivate(string $extensionName, string $environment, bool $rebuildAssets = true): WorkflowResult
    {
        $plan = $this->planner->planDeactivation($extensionName);

        if (!$plan->isSuccess()) {
            return $this->report($plan, 'extension.deactivate', ['extension' => $extensionName, 'environment' => $environment]);
        }

        $extensions = $this->store->extensionsByName($plan->value()['deactivate']);
        $snapshots = $this->store->statusSnapshots($extensions);
        $changes = [];
        $messages = [];

        foreach ($extensions as $extension) {
            if (ExtensionStatus::Active === $extension->status() && $extension->deactivate()) {
                $changes[] = $this->change($extension, 'deactivated');
                $messages[] = $this->deactivatedMessage($extension);
            }
        }

        $messages = [...$messages, ...$this->archiveContentForDeactivatedExtensions($extensions)];

        return $this->report(
            $this->finalizer->finalize($snapshots, $changes, $messages, $environment, $rebuildAssets),
            'extension.deactivate',
            ['extension' => $extensionName, 'environment' => $environment],
        );
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

    private function deactivatedMessage(Extension $extension): Message
    {
        return Message::create(
            ExtensionMessageCode::EXTENSION_LIFECYCLE_DEACTIVATED,
            ExtensionMessageKey::EXTENSION_LIFECYCLE_DEACTIVATED,
            ['%extension%' => $extension->extensionName()],
            ['extension' => $extension->extensionName()],
            MessageLevel::Success,
        );
    }

    /**
     * @param list<Extension> $extensions
     *
     * @return list<Message>
     */
    private function archiveContentForDeactivatedExtensions(array $extensions): array
    {
        if (null === $this->contentSchemaImpact || [] === $extensions) {
            return [];
        }

        return $this->contentSchemaImpact->archivePublicContentForExtensions($extensions)->messages();
    }

    /**
     * @param array<string, ExtensionStatus> $snapshots
     * @param list<array{extension: string, action: string, status: string}> $changes
     */
    private function rollbackContributionFailure(
        string $extensionName,
        string $environment,
        array $snapshots,
        array $changes,
        bool $rebuildAssets,
        WorkflowResult $finalized,
        WorkflowResult $contributions,
    ): WorkflowResult {
        $this->store->restoreStatuses($snapshots);
        $this->entityManager->flush();

        $messages = [
            ...$finalized->messages(),
            ...$contributions->messages(),
            Message::warning(
                ExtensionMessageCode::EXTENSION_LIFECYCLE_ROLLED_BACK,
                ExtensionMessageKey::EXTENSION_LIFECYCLE_ROLLED_BACK,
                ['%count%' => count($snapshots)],
                ['extension_count' => count($snapshots), 'extensions' => array_keys($snapshots)],
            ),
        ];
        $issues = $contributions->issues();
        $rollbackAssetContext = null;

        if ($rebuildAssets && [] !== $changes) {
            $rollbackRebuild = $this->assetRebuilder->rebuild($environment);
            $messages = [...$messages, ...$rollbackRebuild->messages()];
            $rollbackAssetContext = $rollbackRebuild->context();

            if (!$rollbackRebuild->isSuccess()) {
                $issues = [...$issues, ...$rollbackRebuild->issues()];
            }
        }

        return WorkflowResult::failed($issues, [
            'extension' => $extensionName,
            'environment' => $environment,
            'changes' => $changes,
            'asset_rebuild' => $rebuildAssets && [] !== $changes,
            'rolled_back' => true,
            'contribution_context' => $contributions->context(),
            'rollback_asset_rebuild_context' => $rollbackAssetContext,
        ], $messages);
    }

    private function report(WorkflowResult $result, string $operation, array $context = []): WorkflowResult
    {
        return $this->messageReporter->report($result, [
            ...$context,
            'operation' => $operation,
        ]);
    }
}
