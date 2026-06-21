<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Asset\AssetRebuildQueueFactory;
use App\Core\Message\Message;
use App\Core\Message\WorkflowResultMessageReporterInterface;
use App\Core\Operation\OperationExecutor;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Workflow\WorkflowResult;
use Throwable;

final readonly class ExtensionLifecycleAssetRebuilder implements ExtensionLifecycleAssetRebuilderInterface
{
    public function __construct(
        private ActiveExtensionAssetProviderInterface $extensionProvider,
        private AssetRebuildQueueFactory $queueFactory,
        private OperationExecutor $operationExecutor,
        private WorkflowResultMessageReporterInterface $messageReporter,
    ) {
    }

    public function rebuild(string $environment): WorkflowResult
    {
        try {
            $extensions = $this->extensionProvider->extensions();
        } catch (Throwable $error) {
            return $this->report(WorkflowResult::failed([
                Message::exception(
                    ExtensionMessageCode::EXTENSION_ASSET_SYNC_FAILED,
                    ExtensionMessageKey::EXTENSION_ASSET_SYNC_FAILED,
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
            ->executeQueue($this->queueFactory->create($environment, $extensions))
            ->result();
    }

    private function report(WorkflowResult $result, string $environment): WorkflowResult
    {
        return $this->messageReporter->report($result, [
            'operation' => 'extension.asset_rebuild',
            'environment' => $environment,
        ]);
    }
}
