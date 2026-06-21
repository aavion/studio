<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\AdminAcl\AdminFeatureRegistry;
use App\Core\Message\Message;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Workflow\WorkflowResult;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ExtensionLifecycleFinalizer
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ExtensionLifecycleStore $store,
        private ExtensionLifecycleAssetRebuilderInterface $assetRebuilder,
        private ?AdminFeatureRegistry $adminFeatureRegistry = null,
    ) {
    }

    /**
     * @param array<string, ExtensionStatus> $snapshots
     * @param list<array{extension: string, action: string, status: string}> $changes
     * @param list<Message> $messages
     *
     * @return WorkflowResult<array<string, mixed>>
     */
    public function finalize(array $snapshots, array $changes, array $messages, string $environment, bool $rebuildAssets): WorkflowResult
    {
        if ([] === $changes) {
            return $this->success($changes, false, false, $messages);
        }

        $this->entityManager->flush();
        $this->adminFeatureRegistry?->resetCache();

        if (!$rebuildAssets) {
            return $this->success($changes, false, false, $messages);
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

        $this->store->restoreStatuses($snapshots);
        $this->entityManager->flush();
        $this->adminFeatureRegistry?->resetCache();

        return WorkflowResult::failed($rebuild->issues(), [
            'changes' => $changes,
            'asset_rebuild' => true,
            'rolled_back' => true,
            'asset_rebuild_context' => $rebuild->context(),
        ], [
            ...$messages,
            ...$rebuild->messages(),
            Message::warning(
                ExtensionMessageCode::EXTENSION_LIFECYCLE_ROLLED_BACK,
                ExtensionMessageKey::EXTENSION_LIFECYCLE_ROLLED_BACK,
                ['%count%' => count($snapshots)],
                ['extension_count' => count($snapshots), 'extensions' => array_keys($snapshots)],
            ),
        ]);
    }

    /**
     * @param list<array{extension: string, action: string, status: string}> $changes
     * @param list<Message> $messages
     *
     * @return WorkflowResult<array<string, mixed>>
     */
    private function success(array $changes, bool $assetRebuild, bool $rolledBack, array $messages): WorkflowResult
    {
        return WorkflowResult::success([
            'changes' => $changes,
            'asset_rebuild' => $assetRebuild,
            'rolled_back' => $rolledBack,
        ], [
            'changes' => $changes,
            'asset_rebuild' => $assetRebuild,
            'rolled_back' => $rolledBack,
        ], $messages);
    }
}
