<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use App\Core\Asset\AssetRebuildQueueFactory;
use App\Core\Message\Message;
use App\Core\Operation\ActionQueue;
use App\Core\Extension\ActiveExtensionAssetProviderInterface;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Workflow\WorkflowResult;
use Symfony\Component\HttpKernel\KernelInterface;
use Throwable;

final readonly class ExtensionAssetRebuildLiveOperationProvider implements LiveOperationQueueProviderInterface
{
    public function __construct(
        private KernelInterface $kernel,
        private ActiveExtensionAssetProviderInterface $extensionProvider,
        private AssetRebuildQueueFactory $assetRebuildQueueFactory,
    ) {
    }

    public function operation(): string
    {
        return LiveOperationQueueFactory::EXTENSION_ASSET_REBUILD;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return WorkflowResult<ActionQueue>
     */
    public function create(array $payload = []): WorkflowResult
    {
        $environment = $this->environment($payload);

        try {
            $extensions = $this->extensionProvider->extensions();
        } catch (Throwable $error) {
            return WorkflowResult::failed([
                Message::exception(
                    ExtensionMessageCode::EXTENSION_ASSET_SYNC_FAILED,
                    ExtensionMessageKey::EXTENSION_ASSET_SYNC_FAILED,
                    ['%message%' => $error->getMessage()],
                    [
                        'operation' => $this->operation(),
                        'environment' => $environment,
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                    ],
                ),
            ], [
                'operation' => $this->operation(),
                'environment' => $environment,
            ]);
        }

        return WorkflowResult::success($this->assetRebuildQueueFactory->create($environment, $extensions));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function environment(array $payload): string
    {
        $environment = $payload['environment'] ?? $this->kernel->getEnvironment();

        return is_string($environment) && '' !== trim($environment) ? trim($environment) : $this->kernel->getEnvironment();
    }
}
