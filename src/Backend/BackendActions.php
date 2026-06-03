<?php

declare(strict_types=1);

namespace App\Backend;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Operation\ActionQueue;
use App\Core\Operation\Live\LiveOperationQueueFactory;
use App\Core\Operation\Live\LiveOperationStarter;
use App\Core\Operation\OperationExecutor;
use App\Core\Operation\Process\PhpCliUnavailableAction;
use App\Core\Operation\Process\RunCommandAction;
use App\Core\Package\PackageAssetRebuildDispatcher;
use App\Core\Package\PackageDiscoveryRunner;
use App\Core\Process\PhpCliBinaryResolver;
use App\Core\Workflow\WorkflowResult;
use Symfony\Component\HttpKernel\KernelInterface;

final readonly class BackendActions
{
    public const PACKAGE_DISCOVERY = 'package_discovery';
    public const ASSET_REBUILD = 'asset_rebuild';
    public const CACHE_CLEAR = 'cache_clear';

    public function __construct(
        private KernelInterface $kernel,
        private PackageDiscoveryRunner $packageDiscoveryRunner,
        private PackageAssetRebuildDispatcher $assetRebuildDispatcher,
        private OperationExecutor $operationExecutor,
        private LiveOperationStarter $liveOperationStarter,
        private PhpCliBinaryResolver $phpCliBinaryResolver,
    ) {
    }

    /**
     * @param list<string> $ids
     *
     * @return list<array{id: string, label_key: string, variant: string, live: bool}>
     */
    public function definitions(array $ids = []): array
    {
        $definitions = [
            self::PACKAGE_DISCOVERY => [
                'id' => self::PACKAGE_DISCOVERY,
                'label_key' => 'admin.actions.package_discovery.label',
                'variant' => 'secondary',
                'live' => true,
            ],
            self::ASSET_REBUILD => [
                'id' => self::ASSET_REBUILD,
                'label_key' => 'admin.actions.asset_rebuild.label',
                'variant' => 'secondary',
                'live' => true,
            ],
            self::CACHE_CLEAR => [
                'id' => self::CACHE_CLEAR,
                'label_key' => 'admin.actions.cache_clear.label',
                'variant' => 'secondary',
                'live' => true,
            ],
        ];

        if ([] === $ids) {
            return array_values($definitions);
        }

        return array_values(array_filter(
            array_map(static fn (string $id): ?array => $definitions[$id] ?? null, $ids),
        ));
    }

    /**
     * @return WorkflowResult<mixed>
     */
    public function run(string $action): WorkflowResult
    {
        return match ($action) {
            self::PACKAGE_DISCOVERY => ($this->packageDiscoveryRunner)('admin_ui'),
            self::ASSET_REBUILD => $this->assetRebuildDispatcher->dispatch($this->kernel->getEnvironment(), 'admin_ui'),
            self::CACHE_CLEAR => $this->clearCache(),
            default => WorkflowResult::invalid([
                Message::warning(
                    MessageCode::BACKEND_ACTION_UNKNOWN,
                    MessageKey::BACKEND_ACTION_UNKNOWN,
                    ['%action%' => $action],
                    ['action' => $action],
                ),
            ], ['action' => $action]),
        };
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function startLive(string $action): WorkflowResult
    {
        return match ($action) {
            self::PACKAGE_DISCOVERY => $this->liveOperationStarter->start(
                LiveOperationQueueFactory::PACKAGE_DISCOVERY,
                ['environment' => $this->kernel->getEnvironment(), 'trigger' => 'admin_ui'],
                'Package discovery',
            ),
            self::ASSET_REBUILD => $this->liveOperationStarter->start(
                LiveOperationQueueFactory::PACKAGE_ASSET_REBUILD,
                ['environment' => $this->kernel->getEnvironment(), 'trigger' => 'admin_ui'],
                'Asset rebuild',
            ),
            self::CACHE_CLEAR => $this->liveOperationStarter->start(
                LiveOperationQueueFactory::BACKEND_CACHE_CLEAR,
                ['environment' => $this->kernel->getEnvironment(), 'trigger' => 'admin_ui'],
                'Cache clear',
            ),
            default => WorkflowResult::invalid([
                Message::warning(
                    MessageCode::BACKEND_ACTION_UNKNOWN,
                    MessageKey::BACKEND_ACTION_UNKNOWN,
                    ['%action%' => $action],
                    ['action' => $action],
                ),
            ], ['action' => $action]),
        };
    }

    /**
     * @return WorkflowResult<mixed>
     */
    private function clearCache(): WorkflowResult
    {
        $resolution = $this->phpCliBinaryResolver->resolve($this->kernel->getProjectDir());

        if (!$resolution->isAvailable()) {
            return WorkflowResult::failed([
                PhpCliUnavailableAction::message('cache:clear', $resolution->reason(), [
                    'environment' => $this->kernel->getEnvironment(),
                    'trigger' => 'admin_ui',
                ]),
            ], [
                'environment' => $this->kernel->getEnvironment(),
                'trigger' => 'admin_ui',
            ]);
        }

        $queue = ActionQueue::create('backend cache clear', [
            new RunCommandAction([
                ...$resolution->commandPrefix(),
                $this->kernel->getProjectDir().'/bin/console',
                'cache:clear',
                '--env='.$this->kernel->getEnvironment(),
                '--no-interaction',
            ], $this->kernel->getProjectDir(), timeout: 300.0),
        ], context: [
            'environment' => $this->kernel->getEnvironment(),
            'trigger' => 'admin_ui',
        ]);
        $result = $this->operationExecutor->executeQueue($queue)->result();

        if (!$result->isSuccess()) {
            return $result;
        }

        return WorkflowResult::success($result->value(), $result->context(), [
            Message::success(
                MessageKey::BACKEND_ACTION_CACHE_CLEAR_COMPLETED,
                context: ['environment' => $this->kernel->getEnvironment(), 'trigger' => 'admin_ui'],
            ),
        ]);
    }
}
