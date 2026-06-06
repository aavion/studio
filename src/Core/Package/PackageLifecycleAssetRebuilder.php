<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Asset\AssetRebuildQueueFactory;
use App\Core\Message\Message;
use App\Core\Message\WorkflowResultMessageReporterInterface;
use App\Core\Operation\OperationExecutor;
use App\Core\Package\PackageMessageCode;
use App\Core\Package\PackageMessageKey;
use App\Core\Workflow\WorkflowResult;
use Throwable;

final readonly class PackageLifecycleAssetRebuilder implements PackageLifecycleAssetRebuilderInterface
{
    public function __construct(
        private ActivePackageAssetProviderInterface $packageProvider,
        private AssetRebuildQueueFactory $queueFactory,
        private OperationExecutor $operationExecutor,
        private WorkflowResultMessageReporterInterface $messageReporter,
    ) {
    }

    public function rebuild(string $environment): WorkflowResult
    {
        try {
            $packages = $this->packageProvider->packages();
        } catch (Throwable $error) {
            return $this->report(WorkflowResult::failed([
                Message::exception(
                    PackageMessageCode::PACKAGE_ASSET_SYNC_FAILED,
                    PackageMessageKey::PACKAGE_ASSET_SYNC_FAILED,
                    ['%message%' => $error->getMessage()],
                    [
                        'environment' => $environment,
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                    ],
                ),
            ]), $environment);
        }

        return $this->operationExecutor
            ->executeQueue($this->queueFactory->create($environment, $packages))
            ->result();
    }

    private function report(WorkflowResult $result, string $environment): WorkflowResult
    {
        return $this->messageReporter->report($result, [
            'operation' => 'package.asset_rebuild',
            'environment' => $environment,
        ]);
    }
}
