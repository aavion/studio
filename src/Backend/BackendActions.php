<?php

declare(strict_types=1);

namespace App\Backend;

use App\Backend\BackendMessageCode;
use App\Backend\BackendMessageKey;
use App\Core\Access\AccessActor;
use App\Core\Access\AccessLevel;
use App\Core\Access\AccessMessageCode;
use App\Core\Access\AccessMessageKey;
use App\Core\AdminAcl\AdminFeatureAccessPolicy;
use App\Core\Message\Message;
use App\Core\Operation\ActionQueue;
use App\Core\Operation\Live\LiveOperationQueueFactory;
use App\Core\Operation\Live\LiveOperationStarter;
use App\Core\Operation\OperationExecutor;
use App\Core\Operation\Process\PhpCliUnavailableAction;
use App\Core\Operation\Process\RunCommandAction;
use App\Core\Extension\ExtensionAssetRebuildDispatcher;
use App\Core\Extension\ExtensionDiscoveryRunner;
use App\Core\Process\PhpCliBinaryManager;
use App\Core\Workflow\WorkflowResult;
use Symfony\Component\HttpKernel\KernelInterface;

final readonly class BackendActions
{
    public const EXTENSION_DISCOVERY = 'extension_discovery';
    public const ASSET_REBUILD = 'asset_rebuild';
    public const CACHE_CLEAR = 'cache_clear';
    public const GEOIP_DATABASE_UPDATE = 'geoip_database_update';

    public function __construct(
        private KernelInterface $kernel,
        private ExtensionDiscoveryRunner $extensionDiscoveryRunner,
        private ExtensionAssetRebuildDispatcher $assetRebuildDispatcher,
        private OperationExecutor $operationExecutor,
        private LiveOperationStarter $liveOperationStarter,
        private PhpCliBinaryManager $phpCliBinaryManager,
        private AdminFeatureAccessPolicy $adminAcl,
    ) {
    }

    /**
     * @param list<string> $ids
     *
     * @return list<array{id: string, label_key: string, variant: string, live: bool}>
     */
    public function definitions(array $ids = [], ?AccessActor $actor = null): array
    {
        $actor ??= AccessActor::fromAccess(AccessLevel::ADMIN);
        $definitions = [
            self::EXTENSION_DISCOVERY => [
                'id' => self::EXTENSION_DISCOVERY,
                'label_key' => 'admin.actions.extension_discovery.label',
                'variant' => 'secondary',
                'live' => true,
                'access_feature' => 'admin.extensions',
            ],
            self::ASSET_REBUILD => [
                'id' => self::ASSET_REBUILD,
                'label_key' => 'admin.actions.asset_rebuild.label',
                'variant' => 'secondary',
                'live' => true,
                'access_feature' => 'admin.actions.maintenance',
            ],
            self::CACHE_CLEAR => [
                'id' => self::CACHE_CLEAR,
                'label_key' => 'admin.actions.cache_clear.label',
                'variant' => 'secondary',
                'live' => true,
                'access_feature' => 'admin.actions.maintenance',
            ],
            self::GEOIP_DATABASE_UPDATE => [
                'id' => self::GEOIP_DATABASE_UPDATE,
                'label_key' => 'admin.actions.geoip_database_update.label',
                'variant' => 'secondary',
                'live' => true,
                'access_feature' => 'admin.settings.statistics.geoip',
                'access_configurable' => true,
            ],
        ];

        if ([] === $ids) {
            return array_values(array_map(
                fn (array $definition): array => $this->publicDefinition($definition, $actor),
                array_filter(
                    $definitions,
                    fn (array $definition): bool => $this->definitionAllows($definition, $actor),
                ),
            ));
        }

        $visible = [];
        foreach ($ids as $id) {
            $definition = $definitions[$id] ?? null;

            if ($this->definitionAllows($definition, $actor)) {
                $visible[] = $this->publicDefinition($definition, $actor);
            }
        }

        return $visible;
    }

    /**
     * @return WorkflowResult<mixed>
     */
    public function run(string $action, ?AccessActor $actor = null): WorkflowResult
    {
        $actor ??= AccessActor::fromAccess(AccessLevel::ADMIN);

        if (!$this->actionAllows($action, $actor)) {
            return $this->accessDenied($action, $actor);
        }

        return match ($action) {
            self::EXTENSION_DISCOVERY => ($this->extensionDiscoveryRunner)('admin_ui'),
            self::ASSET_REBUILD => $this->assetRebuildDispatcher->dispatch($this->kernel->getEnvironment(), 'admin_ui'),
            self::CACHE_CLEAR => $this->clearCache(),
            self::GEOIP_DATABASE_UPDATE => $this->startLive($action, $actor),
            default => WorkflowResult::invalid([
                Message::warning(
                    BackendMessageCode::BACKEND_ACTION_UNKNOWN,
                    BackendMessageKey::BACKEND_ACTION_UNKNOWN,
                    ['%action%' => $action],
                    ['action' => $action],
                ),
            ], ['action' => $action]),
        };
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function startLive(string $action, ?AccessActor $actor = null): WorkflowResult
    {
        $actor ??= AccessActor::fromAccess(AccessLevel::ADMIN);

        if (!$this->actionAllows($action, $actor)) {
            return $this->accessDenied($action, $actor);
        }

        return match ($action) {
            self::EXTENSION_DISCOVERY => $this->liveOperationStarter->startTranslated(
                LiveOperationQueueFactory::EXTENSION_DISCOVERY,
                ['environment' => $this->kernel->getEnvironment(), 'trigger' => 'admin_ui'],
                'admin.actions.extension_discovery.label',
            ),
            self::ASSET_REBUILD => $this->liveOperationStarter->startTranslated(
                LiveOperationQueueFactory::EXTENSION_ASSET_REBUILD,
                ['environment' => $this->kernel->getEnvironment(), 'trigger' => 'admin_ui'],
                'admin.actions.asset_rebuild.label',
            ),
            self::CACHE_CLEAR => $this->liveOperationStarter->startTranslated(
                LiveOperationQueueFactory::BACKEND_CACHE_CLEAR,
                ['environment' => $this->kernel->getEnvironment(), 'trigger' => 'admin_ui'],
                'admin.actions.cache_clear.label',
            ),
            self::GEOIP_DATABASE_UPDATE => $this->liveOperationStarter->startTranslated(
                LiveOperationQueueFactory::GEOIP_DATABASE_UPDATE,
                ['environment' => $this->kernel->getEnvironment(), 'trigger' => 'admin_ui'],
                'admin.actions.geoip_database_update.label',
            ),
            default => WorkflowResult::invalid([
                Message::warning(
                    BackendMessageCode::BACKEND_ACTION_UNKNOWN,
                    BackendMessageKey::BACKEND_ACTION_UNKNOWN,
                    ['%action%' => $action],
                    ['action' => $action],
                ),
            ], ['action' => $action]),
        };
    }

    /**
     * @param array<string, mixed>|null $definition
     */
    private function definitionAllows(?array $definition, AccessActor $actor): bool
    {
        if (null === $definition) {
            return false;
        }

        $feature = $definition['access_feature'] ?? null;

        return !is_string($feature) || $this->adminAcl->isVisible($feature, $actor);
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private function publicDefinition(array $definition, AccessActor $actor): array
    {
        $feature = $definition['access_feature'] ?? null;

        if (is_string($feature)) {
            $definition['disabled'] = !$this->adminAcl->isMutable($feature, $actor);
        }

        return $definition;
    }

    private function actionAllows(string $action, AccessActor $actor): bool
    {
        if (!in_array($action, [
            self::EXTENSION_DISCOVERY,
            self::ASSET_REBUILD,
            self::CACHE_CLEAR,
            self::GEOIP_DATABASE_UPDATE,
        ], true)) {
            return true;
        }

        $definitions = $this->definitions([$action], $actor);
        $definition = $definitions[0] ?? null;

        return is_array($definition) && true !== ($definition['disabled'] ?? false);
    }

    /**
     * @return WorkflowResult<mixed>
     */
    private function accessDenied(string $action, AccessActor $actor): WorkflowResult
    {
        return WorkflowResult::invalid([
            Message::warning(
                AccessMessageCode::ACCESS_DENIED,
                AccessMessageKey::ACCESS_DENIED,
                [
                    '%capability%' => 'backend.action.'.$action,
                    '%required_level%' => AccessLevel::OWNER,
                    '%actor_level%' => $actor->accessLevel(),
                ],
                [
                    ...$actor->toContext(),
                    'capability' => 'backend.action.'.$action,
                    'required_access_level' => AccessLevel::OWNER,
                ],
            ),
        ], [
            'action' => $action,
            'required_access_level' => AccessLevel::OWNER,
            'actor_access_level' => $actor->accessLevel(),
        ]);
    }

    /**
     * @return WorkflowResult<mixed>
     */
    private function clearCache(): WorkflowResult
    {
        $resolution = $this->phpCliBinaryManager->resolve($this->kernel->getProjectDir(), $this->kernel->getEnvironment(), persistPreference: true);

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
                BackendMessageKey::BACKEND_ACTION_CACHE_CLEAR_COMPLETED,
                context: ['environment' => $this->kernel->getEnvironment(), 'trigger' => 'admin_ui'],
            ),
        ]);
    }
}
