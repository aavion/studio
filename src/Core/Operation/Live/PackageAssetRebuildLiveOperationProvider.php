<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use App\Core\Asset\AssetRebuildQueueFactory;
use App\Core\Message\Message;
use App\Core\Operation\ActionQueue;
use App\Core\Package\ActivePackageAssetProviderInterface;
use App\Core\Package\PackageMessageCode;
use App\Core\Package\PackageMessageKey;
use App\Core\Workflow\WorkflowResult;
use Symfony\Component\HttpKernel\KernelInterface;
use Throwable;

final readonly class PackageAssetRebuildLiveOperationProvider implements LiveOperationQueueProviderInterface
{
    public function __construct(
        private KernelInterface $kernel,
        private ActivePackageAssetProviderInterface $packageProvider,
        private AssetRebuildQueueFactory $assetRebuildQueueFactory,
    ) {
    }

    public function operation(): string
    {
        return LiveOperationQueueFactory::PACKAGE_ASSET_REBUILD;
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
            $packages = $this->packageProvider->packages();
        } catch (Throwable $error) {
            return WorkflowResult::failed([
                Message::exception(
                    PackageMessageCode::PACKAGE_ASSET_SYNC_FAILED,
                    PackageMessageKey::PACKAGE_ASSET_SYNC_FAILED,
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

        return WorkflowResult::success($this->assetRebuildQueueFactory->create($environment, $packages));
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
