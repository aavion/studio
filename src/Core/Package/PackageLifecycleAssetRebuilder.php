<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Asset\AssetRebuildQueueFactory;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Operation\OperationExecutor;
use App\Core\Workflow\OperationIssue;
use App\Core\Workflow\OperationResult;
use Throwable;

final readonly class PackageLifecycleAssetRebuilder implements PackageLifecycleAssetRebuilderInterface
{
    public function __construct(
        private ActivePackageAssetProviderInterface $packageProvider,
        private AssetRebuildQueueFactory $queueFactory,
        private OperationExecutor $operationExecutor,
    ) {
    }

    public function rebuild(string $environment): OperationResult
    {
        try {
            $packages = $this->packageProvider->packages();
        } catch (Throwable $error) {
            return OperationResult::failed([
                OperationIssue::create(
                    MessageCode::PACKAGE_ASSET_SYNC_FAILED,
                    MessageKey::PACKAGE_ASSET_SYNC_FAILED,
                    ['%message%' => $error->getMessage()],
                    [
                        'environment' => $environment,
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                    ],
                    MessageLevel::Error,
                ),
            ]);
        }

        return $this->operationExecutor
            ->executeQueue($this->queueFactory->create($environment, $packages))
            ->result();
    }
}
