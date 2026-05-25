<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Event\PublicHookFailedEvent;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Workflow\OperationResult;
use App\Entity\ExtensionPackage;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PackageRuntimeFailureHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?PackageAssetRebuildDispatcher $assetRebuildDispatcher = null,
        private string $environment = 'test',
    ) {
    }

    /**
     * @return OperationResult<array<string, mixed>>
     */
    public function handleHookFailure(PublicHookFailedEvent $event): OperationResult
    {
        $packageName = $event->package();

        if (null === $packageName) {
            return OperationResult::success([
                'package' => null,
                'faulty' => false,
            ], [
                'package' => null,
                'reason' => 'package_not_identified',
            ]);
        }

        $package = $this->package($packageName);

        if (null === $package) {
            return OperationResult::success([
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

        if (ExtensionPackageStatus::Active === $package->status()) {
            $faulty = $package->markFaulty($package->path(), $package->manifestVersion(), [
                ...$package->metadata(),
                'registry_state' => 'faulty',
                'runtime_failure' => $failure,
            ]);
        } else {
            $package->recordRuntimeFailure($failure);
        }

        $this->entityManager->flush();
        $assetRebuild = $faulty
            ? $this->assetRebuildDispatcher?->dispatch($this->environment, 'package_runtime_failure')
            : null;

        return OperationResult::success([
            'package' => $packageName,
            'faulty' => $faulty,
            'asset_rebuild' => null !== $assetRebuild && $assetRebuild->isSuccess(),
        ], [
            'package' => $packageName,
            'faulty' => $faulty,
            'asset_rebuild' => $assetRebuild?->toArray(),
        ], [
            Message::warning(
                MessageCode::PACKAGE_LIFECYCLE_RUNTIME_FAILURE,
                MessageKey::PACKAGE_LIFECYCLE_RUNTIME_FAILURE,
                ['%package%' => $packageName],
                ['package' => $packageName, 'faulty' => $faulty],
            ),
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
