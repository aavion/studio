<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Event\PublicHookFailedEvent;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Message\WorkflowResultMessageReporterInterface;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ExtensionPackage;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PackageRuntimeFailureHandler
{
    private PackageDependentDeactivator $dependentDeactivator;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private WorkflowResultMessageReporterInterface $messageReporter,
        private ?PackageAssetRebuildDispatcher $assetRebuildDispatcher = null,
        private string $environment = 'test',
        ?PackageDependentDeactivator $dependentDeactivator = null,
    ) {
        $this->dependentDeactivator = $dependentDeactivator ?? new PackageDependentDeactivator($entityManager);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function handleHookFailure(PublicHookFailedEvent $event): WorkflowResult
    {
        return $this->report($this->doHandleHookFailure($event), [
            'package' => $event->package(),
            'hook' => $event->hook()->eventClass(),
        ]);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    private function doHandleHookFailure(PublicHookFailedEvent $event): WorkflowResult
    {
        $packageName = $event->package();

        if (null === $packageName) {
            return WorkflowResult::success([
                'package' => null,
                'faulty' => false,
            ], [
                'package' => null,
                'reason' => 'package_not_identified',
            ]);
        }

        $package = $this->package($packageName);

        if (null === $package) {
            return WorkflowResult::success([
                'package' => $packageName,
                'faulty' => false,
            ], [
                'package' => $packageName,
                'reason' => 'package_not_registered',
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

        if (ExtensionPackageStatus::Active === $package->status()) {
            $faulty = $package->markFaulty($package->path(), $package->manifestVersion(), [
                ...$package->metadata(),
                'registry_state' => 'faulty',
                'runtime_failure' => $failure,
            ]);

            if ($faulty) {
                $deactivation = $this->dependentDeactivator->deactivateActiveDependents($package, 'runtime_fault');
                $dependentChanges = $deactivation['changes'];
                $dependentMessages = $deactivation['messages'];
            }
        } else {
            $package->recordRuntimeFailure($failure);
        }

        $this->entityManager->flush();
        $assetRebuild = $faulty
            ? $this->assetRebuildDispatcher?->dispatch($this->environment, 'package_runtime_failure')
            : null;

        return WorkflowResult::success([
            'package' => $packageName,
            'faulty' => $faulty,
            'asset_rebuild' => null !== $assetRebuild && $assetRebuild->isSuccess(),
            'deactivated_dependents' => array_column($dependentChanges, 'package'),
        ], [
            'package' => $packageName,
            'faulty' => $faulty,
            'asset_rebuild' => $assetRebuild?->toArray(),
            'deactivated_dependents' => $dependentChanges,
        ], [
            Message::create(
                MessageCode::PACKAGE_LIFECYCLE_RUNTIME_FAILURE,
                MessageKey::PACKAGE_LIFECYCLE_RUNTIME_FAILURE,
                ['%package%' => $packageName],
                ['package' => $packageName, 'faulty' => $faulty],
                $faulty ? MessageLevel::Error : MessageLevel::Warning,
            ),
            ...$dependentMessages,
        ]);
    }

    private function report(WorkflowResult $result, array $context = []): WorkflowResult
    {
        return $this->messageReporter->report($result, [
            ...$context,
            'operation' => 'package.runtime_failure',
        ]);
    }

    private function package(string $packageName): ?ExtensionPackage
    {
        $package = $this->entityManager->getRepository(ExtensionPackage::class)->findOneBy([
            'packageName' => $packageName,
        ]);

        return $package instanceof ExtensionPackage ? $package : null;
    }
}
