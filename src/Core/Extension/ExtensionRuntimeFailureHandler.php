<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Event\PublicHookFailedEvent;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Message\WorkflowResultMessageReporterInterface;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Workflow\WorkflowResult;
use App\Entity\Extension;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ExtensionRuntimeFailureHandler
{
    private ExtensionDependentDeactivator $dependentDeactivator;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private WorkflowResultMessageReporterInterface $messageReporter,
        private ?ExtensionAssetRebuildDispatcher $assetRebuildDispatcher = null,
        private string $environment = 'test',
        ?ExtensionDependentDeactivator $dependentDeactivator = null,
    ) {
        $this->dependentDeactivator = $dependentDeactivator ?? new ExtensionDependentDeactivator($entityManager);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function handleHookFailure(PublicHookFailedEvent $event): WorkflowResult
    {
        return $this->report($this->doHandleHookFailure($event), [
            'extension' => $event->extension(),
            'hook' => $event->hook()->eventClass(),
        ]);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    private function doHandleHookFailure(PublicHookFailedEvent $event): WorkflowResult
    {
        $extensionName = $event->extension();

        if (null === $extensionName) {
            return WorkflowResult::success([
                'extension' => null,
                'faulty' => false,
            ], [
                'extension' => null,
                'reason' => 'extension_not_identified',
            ]);
        }

        $extension = $this->extension($extensionName);

        if (null === $extension) {
            return WorkflowResult::success([
                'extension' => $extensionName,
                'faulty' => false,
            ], [
                'extension' => $extensionName,
                'reason' => 'extension_not_registered',
            ]);
        }

        $failure = [
            'hook' => $event->hook()->eventClass(),
            'issue' => $event->issue()->toArray(),
            'exception' => $event->exception()::class,
            'message' => $event->exception()->getMessage(),
            'context' => $event->context(),
        ];

        $faulty = false;
        $dependentChanges = [];
        $dependentMessages = [];

        if (ExtensionStatus::Active === $extension->status()) {
            $faulty = $extension->markFaulty($extension->path(), $extension->manifestVersion(), [
                ...$extension->metadata(),
                'registry_state' => 'faulty',
                'runtime_failure' => $failure,
            ]);

            if ($faulty) {
                $deactivation = $this->dependentDeactivator->deactivateActiveDependents($extension, 'runtime_fault');
                $dependentChanges = $deactivation['changes'];
                $dependentMessages = $deactivation['messages'];
            }
        } else {
            $extension->recordRuntimeFailure($failure);
        }

        $this->entityManager->flush();
        $assetRebuild = $faulty
            ? $this->assetRebuildDispatcher?->dispatch($this->environment, 'extension_runtime_failure')
            : null;

        return WorkflowResult::success([
            'extension' => $extensionName,
            'faulty' => $faulty,
            'asset_rebuild' => null !== $assetRebuild && $assetRebuild->isSuccess(),
            'deactivated_dependents' => array_column($dependentChanges, 'extension'),
        ], [
            'extension' => $extensionName,
            'faulty' => $faulty,
            'asset_rebuild' => $assetRebuild?->toArray(),
            'deactivated_dependents' => $dependentChanges,
        ], [
            Message::create(
                ExtensionMessageCode::EXTENSION_LIFECYCLE_RUNTIME_FAILURE,
                ExtensionMessageKey::EXTENSION_LIFECYCLE_RUNTIME_FAILURE,
                ['%extension%' => $extensionName],
                ['extension' => $extensionName, 'faulty' => $faulty],
                $faulty ? MessageLevel::Error : MessageLevel::Warning,
            ),
            ...$dependentMessages,
        ]);
    }

    private function report(WorkflowResult $result, array $context = []): WorkflowResult
    {
        return $this->messageReporter->report($result, [
            ...$context,
            'operation' => 'extension.runtime_failure',
        ]);
    }

    private function extension(string $extensionName): ?Extension
    {
        $extension = $this->entityManager->getRepository(Extension::class)->findOneBy([
            'extensionName' => $extensionName,
        ]);

        return $extension instanceof Extension ? $extension : null;
    }
}
