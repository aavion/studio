<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Workflow\WorkflowResult;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PackageRegistrySyncFinalizer
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?PackageAssetRebuildDispatcher $assetRebuildDispatcher = null,
        private ?PackageLifecycleAssetRebuilderInterface $assetRebuildFallback = null,
        private string $environment = 'test',
    ) {
    }

    /**
     * @param list<array{package: string, action: string, status: string}> $changes
     * @param list<Message> $messages
     * @param list<string> $assetRebuildTriggers
     *
     * @return WorkflowResult<list<array{package: string, action: string, status: string}>>
     */
    public function finalize(array $changes, array $messages, array $assetRebuildTriggers): WorkflowResult
    {
        $this->entityManager->flush();

        $assetRebuild = null;
        if ([] !== $assetRebuildTriggers) {
            $assetRebuild = $this->assetRebuildDispatcher?->dispatch($this->environment, 'package_registry_state_exit');
            $messages = [...$messages, ...($assetRebuild?->messages() ?? [])];

            if (null !== $assetRebuild && !$assetRebuild->isSuccess()) {
                $recovery = $this->recoverFromDispatchFailure($assetRebuild, $messages);
                $assetRebuild = $recovery['asset_rebuild'];
                $messages = $recovery['messages'];

                if (!$recovery['fallback_completed']) {
                    return WorkflowResult::failed($recovery['issues'], [
                        'change_count' => count($changes),
                        'changes' => $changes,
                        'asset_rebuild' => $assetRebuild->toArray(),
                        'asset_rebuild_triggers' => array_values(array_unique($assetRebuildTriggers)),
                    ], $messages);
                }
            }
        }

        $messages[] = Message::create(
            PackageMessageCode::PACKAGE_REGISTRY_SYNC_COMPLETED,
            PackageMessageKey::PACKAGE_REGISTRY_SYNC_COMPLETED,
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

    /**
     * @param list<Message> $messages
     *
     * @return array{
     *     asset_rebuild: WorkflowResult<array<string, mixed>>,
     *     fallback_completed: bool,
     *     issues: list<Message>,
     *     messages: list<Message>
     * }
     */
    private function recoverFromDispatchFailure(WorkflowResult $dispatchFailure, array $messages): array
    {
        $fallback = $this->assetRebuildFallback?->rebuild($this->environment);
        $fallbackCompleted = null !== $fallback && $fallback->isSuccess();
        $messages = [
            ...$messages,
            ...$dispatchFailure->issues(),
            ...($fallback?->messages() ?? []),
            ...($fallback?->issues() ?? []),
        ];
        $context = [
            'deferred' => false,
            'dispatch' => $dispatchFailure->toArray(),
            'fallback' => $fallback?->toArray(),
            'fallback_completed' => $fallbackCompleted,
        ];
        $assetRebuild = WorkflowResult::success($context, [
            ...$context,
            'stale_risk' => !$fallbackCompleted,
        ]);

        return [
            'asset_rebuild' => $assetRebuild,
            'fallback_completed' => $fallbackCompleted,
            'issues' => $fallback?->issues() ?: $dispatchFailure->issues(),
            'messages' => $messages,
        ];
    }
}
