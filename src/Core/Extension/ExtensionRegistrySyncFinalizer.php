<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\AdminAcl\AdminFeatureRegistry;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Workflow\WorkflowResult;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ExtensionRegistrySyncFinalizer
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ExtensionLifecycleAssetRebuilderInterface $assetRebuilder = null,
        private string $environment = 'test',
        private ?AdminFeatureRegistry $adminFeatureRegistry = null,
    ) {
    }

    /**
     * @param list<array{extension: string, action: string, status: string}> $changes
     * @param list<Message> $messages
     * @param list<string> $assetRebuildTriggers
     *
     * @return WorkflowResult<list<array{extension: string, action: string, status: string}>>
     */
    public function finalize(array $changes, array $messages, array $assetRebuildTriggers): WorkflowResult
    {
        $this->entityManager->flush();
        if ([] !== $changes) {
            $this->adminFeatureRegistry?->resetCache();
        }

        $assetRebuild = null;
        if ([] !== $assetRebuildTriggers) {
            $assetRebuild = $this->assetRebuilder?->rebuild($this->environment);
            $messages = [...$messages, ...($assetRebuild?->messages() ?? [])];

            if (null !== $assetRebuild && !$assetRebuild->isSuccess()) {
                return WorkflowResult::failed($assetRebuild->issues(), [
                    'change_count' => count($changes),
                    'changes' => $changes,
                    'asset_rebuild' => $assetRebuild->toArray(),
                    'asset_rebuild_triggers' => array_values(array_unique($assetRebuildTriggers)),
                    'stale_risk' => true,
                ], $messages);
            }
        }

        $messages[] = Message::create(
            ExtensionMessageCode::EXTENSION_REGISTRY_SYNC_COMPLETED,
            ExtensionMessageKey::EXTENSION_REGISTRY_SYNC_COMPLETED,
            ['%count%' => count($changes)],
            ['change_count' => count($changes), 'changes' => $changes],
            MessageLevel::Success,
        );

        return WorkflowResult::success($changes, [
            'change_count' => count($changes),
            'changes' => $changes,
            'asset_rebuild' => $assetRebuild?->toArray(),
            'asset_rebuild_triggers' => array_values(array_unique($assetRebuildTriggers)),
        ], $messages);
    }
}
