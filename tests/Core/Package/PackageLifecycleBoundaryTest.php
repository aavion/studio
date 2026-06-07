<?php

declare(strict_types=1);

namespace App\Tests\Core\Package;

use App\Core\Event\EventHookDescriptor;
use App\Core\Event\EventHookMode;
use App\Core\Event\EventMessageCode;
use App\Core\Event\EventMessageKey;
use App\Core\Event\PublicHookFailedEvent;
use App\Core\Message\Message;
use App\Core\Package\ActivePackageProvider;
use App\Core\Package\PackageActivator;
use App\Core\Package\PackageAssetRebuildDispatcher;
use App\Core\Package\PackageAssetRebuildMessage;
use App\Core\Package\PackageAssetRebuildMessageHandler;
use App\Core\Package\PackageFaultResetter;
use App\Core\Package\PackageLifecycleAssetRebuilderInterface;
use App\Core\Package\PackageLifecycleCleanupRunnerInterface;
use App\Core\Package\PackagePhpLoader;
use App\Core\Package\PackageRemover;
use App\Core\Package\PackageRuntimeContributionRegistry;
use App\Core\Package\PackageRuntimeFailureHandler;
use App\Core\Package\PackageScope;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ExtensionPackage;
use App\Tests\Support\FilesystemTestHelper;
use App\Tests\Support\NullWorkflowResultMessageReporter;
use App\Tests\Support\RecordingMessageBus;
use App\View\ViewContextEvent;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class PackageLifecycleBoundaryTest extends KernelTestCase
{
    use FilesystemTestHelper;

    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private BoundaryPackageLifecycleAssetRebuilder $assetRebuilder;
    private RecordingPackageLifecycleCleanupRunner $cleanupRunner;
    private string $projectDir;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->assetRebuilder = new BoundaryPackageLifecycleAssetRebuilder();
        $this->cleanupRunner = new RecordingPackageLifecycleCleanupRunner();
        $this->projectDir = $this->createTemporaryDirectory('system-package-lifecycle-boundary');
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        $this->removeDirectory($this->projectDir);

        parent::tearDown();
    }

    public function testActivePackageProviderReturnsOnlyActiveRealPackages(): void
    {
        $this->insertPackage('demo-module', ['module'], 'active');
        $this->insertPackage('demo-theme', ['frontend-theme'], 'active');
        $this->insertPackage('inactive-module', ['module'], 'inactive');
        $this->insertPackage('system-local', ['system-template'], 'active', path: '.');

        $provider = new ActivePackageProvider($this->entityManager);

        self::assertSame(['demo-module', 'demo-theme'], array_map(
            static fn (ExtensionPackage $package): string => $package->packageName(),
            $provider->packages(),
        ));
        self::assertSame(['demo-module'], array_map(
            static fn (ExtensionPackage $package): string => $package->packageName(),
            $provider->packages(PackageScope::Module),
        ));
        self::assertSame('demo-theme', $provider->package('demo-theme')?->packageName());
        self::assertNull($provider->package('inactive-module'));
    }

    public function testRuntimeHookFailureMarksIdentifiedActivePackageFaulty(): void
    {
        $this->insertPackage('demo-module', ['module'], 'active');
        $this->insertPackage('demo-addon', ['module'], 'active', dependencies: '[["demo-module", "1.0.0"]]');

        $messageBus = new RecordingMessageBus();

        $result = (new PackageRuntimeFailureHandler(
            $this->entityManager,
            new NullWorkflowResultMessageReporter(),
            new PackageAssetRebuildDispatcher($messageBus, new NullWorkflowResultMessageReporter()),
            'test',
        ))->handleHookFailure(new PublicHookFailedEvent(
            new ViewContextEvent([]),
            new EventHookDescriptor(ViewContextEvent::class, 'view', EventHookMode::Extend, EventMessageKey::EVENT_HOOK_VIEW_CONTEXT_SUMMARY, mutable: true),
            Message::create(EventMessageCode::EVENT_HOOK_LISTENER_FAILED, EventMessageKey::EVENT_HOOK_LISTENER_FAILED, ['%event%' => ViewContextEvent::class]),
            new RuntimeException('listener failed'),
            ['route' => 'demo'],
            'demo-module',
        ));

        self::assertTrue($result->isSuccess());
        self::assertTrue($result->value()['faulty']);
        self::assertSame('faulty', $this->packageStatus('demo-module'));
        self::assertSame('inactive', $this->packageStatus('demo-addon'));
        self::assertSame(['demo-addon'], $result->value()['deactivated_dependents']);
        self::assertSame(ViewContextEvent::class, $this->metadata('demo-module')['runtime_failure']['hook']);
        self::assertSame('faulty', $this->metadata('demo-module')['registry_state']);
        self::assertContains('message.package.lifecycle.dependent_deactivated', array_map(
            static fn (Message $message): string => $message->translationKey(),
            $result->messages(),
        ));
        self::assertCount(1, $messageBus->messages());
        self::assertInstanceOf(PackageAssetRebuildMessage::class, $messageBus->messages()[0]);
        self::assertSame('test', $messageBus->messages()[0]->environment());
        self::assertSame('package_runtime_failure', $messageBus->messages()[0]->trigger());
    }

    public function testPackagePhpLoaderIncludesOnlyActivePackageLoaders(): void
    {
        $this->insertPackage('demo-module', ['module'], 'active');
        $this->insertPackage('inactive-module', ['module'], 'inactive');
        $this->writeTestFile($this->projectDir, 'packages/demo-module/package.php', <<<'PHP'
            <?php

            file_put_contents(__DIR__.'/loaded.txt', 'yes');

            return static function ($package): void {
                file_put_contents(__DIR__.'/called.txt', $package->packageName());
            };
            PHP);
        $this->writeTestFile($this->projectDir, 'packages/inactive-module/package.php', <<<'PHP'
            <?php

            file_put_contents(__DIR__.'/loaded.txt', 'no');
            PHP);

        $result = (new PackagePhpLoader(
            new ActivePackageProvider($this->entityManager),
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
        ))->loadActivePackages();

        self::assertTrue($result->isSuccess());
        self::assertSame(['demo-module'], $result->value()['loaded']);
        self::assertFileExists($this->projectDir.'/packages/demo-module/loaded.txt');
        self::assertSame('demo-module', file_get_contents($this->projectDir.'/packages/demo-module/called.txt'));
        self::assertFileDoesNotExist($this->projectDir.'/packages/inactive-module/loaded.txt');
    }

    public function testPackagePhpLoaderRegistersRuntimeContributions(): void
    {
        $this->insertPackage('demo-module', ['module'], 'active');
        $this->writeTestFile($this->projectDir, 'packages/demo-module/package.php', <<<'PHP'
            <?php

            use App\Api\Endpoint\ApiEndpointDefinition;
            use App\Api\Endpoint\ApiEndpointHandlerInterface;
            use App\Api\Endpoint\PackageApiEndpointPath;
            use App\Core\Package\PackageContributions;
            use App\Core\Package\Settings\PackageSettingDefinition;
            use App\View\Injection\ConfigurableStaticViewInjectionRoute;
            use App\View\Injection\ConfigurableStaticViewInjectionSet;
            use App\View\Injection\DynamicViewInjection;
            use App\View\Injection\DynamicViewInjectionSlot;
            use App\View\Injection\StaticViewInjection;
            use App\View\Injection\ViewSurface;
            use Symfony\Component\HttpFoundation\JsonResponse;
            use Symfony\Component\HttpFoundation\Request;
            use Symfony\Component\HttpFoundation\Response;

            return PackageContributions::create()
                ->staticView(new StaticViewInjection(
                    'pkg-demo-module-route',
                    ViewSurface::Public,
                    'demo-module',
                    'pkg.demo-module.widget',
                    '@frontend/demo-module/frontend.html.twig',
                ))
                ->configurableStaticViews(new ConfigurableStaticViewInjectionSet(
                    'demo-module',
                    'demo.route',
                    ViewSurface::Public,
                    'demo',
                    [
                        new ConfigurableStaticViewInjectionRoute(
                            'pkg-demo-module-configurable-route',
                            '',
                            'pkg.demo-module.widget',
                            '@frontend/demo-module/frontend.html.twig',
                        ),
                    ],
                ))
                ->dynamicView(new DynamicViewInjection(
                    'pkg-demo-module-after-content',
                    ViewSurface::Public,
                    DynamicViewInjectionSlot::AfterContent,
                    '@frontend/demo-module/after-content.html.twig',
                ))
                ->setting(new PackageSettingDefinition(
                    'demo-module',
                    'display.mode',
                    'pkg.demo-module.settings.display_mode.label',
                    'compact',
                ))
                ->apiEndpoint(new ApiEndpointDefinition(
                    'package',
                    'GET',
                    PackageApiEndpointPath::path($package->packageName(), 'demo'),
                    'api_v1_endpoint_dispatch',
                    'getDemoModulePackageEndpoint',
                    'Return package demo data.',
                    'packages.demo-module.demo',
                    ['packages'],
                    responseSchema: ['type' => 'object'],
                ))
                ->apiEndpointHandler(new class implements ApiEndpointHandlerInterface {
                    public function apiEndpointHandlerKey(): string
                    {
                        return 'packages.demo-module.demo';
                    }

                    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response
                    {
                        return new JsonResponse(['data' => ['type' => 'package_demo']]);
                    }
                });
            PHP);
        $registry = new PackageRuntimeContributionRegistry();

        $result = (new PackagePhpLoader(
            new ActivePackageProvider($this->entityManager),
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
            runtimeContributions: $registry,
        ))->loadActivePackages();

        self::assertTrue($result->isSuccess());
        self::assertSame('pkg-demo-module-route', $registry->staticViewInjections()[0]->uid());
        self::assertSame('demo-module', $registry->staticViewInjections()[0]->pathSlug());
        self::assertSame('pkg-demo-module-configurable-route', $registry->staticViewInjections()[1]->uid());
        self::assertSame('demo', $registry->staticViewInjections()[1]->pathSlug());
        self::assertSame('pkg-demo-module-after-content', $registry->dynamicViewInjections()[0]->uid());
        self::assertSame('display.mode', $registry->packageSettings()[0]->key());
        self::assertSame('/api/v1/packages/demo-module/demo', $registry->apiEndpoints()[0]->path());
        self::assertSame('packages.demo-module.demo', $registry->apiEndpointHandlers()[0]->apiEndpointHandlerKey());
    }

    public function testPackagePhpLoaderDoesNotKeepPartialRuntimeContributionsAfterFailure(): void
    {
        $this->insertPackage('broken-module', ['module'], 'active');
        $messageBus = new RecordingMessageBus();
        $this->writeTestFile($this->projectDir, 'packages/broken-module/package.php', <<<'PHP'
            <?php

            use App\View\Injection\StaticViewInjection;
            use App\View\Injection\ViewSurface;

            return [
                new StaticViewInjection(
                    'pkg-broken-module-route',
                    ViewSurface::Public,
                    'broken-module',
                    'pkg.broken-module.widget',
                    '@frontend/broken-module/frontend.html.twig',
                ),
                new stdClass(),
            ];
            PHP);
        $registry = new PackageRuntimeContributionRegistry();

        $result = (new PackagePhpLoader(
            new ActivePackageProvider($this->entityManager),
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
            new PackageAssetRebuildDispatcher($messageBus, new NullWorkflowResultMessageReporter()),
            'test',
            runtimeContributions: $registry,
        ))->loadActivePackages();

        self::assertFalse($result->isSuccess());
        self::assertSame('package.lifecycle.php_load_failed', $result->firstIssue()?->code());
        self::assertSame('message.package.runtime.contribution_unsupported', $result->firstIssue()?->context()['previous_message']['key'] ?? null);
        self::assertSame([], $registry->staticViewInjections());
        self::assertSame('faulty', $this->packageStatus('broken-module'));
    }

    public function testPackagePhpLoaderRejectsElevatedSchedulerContributions(): void
    {
        $this->insertPackage('scheduler-module', ['module'], 'active');
        $this->writeTestFile($this->projectDir, 'packages/scheduler-module/package.php', <<<'PHP'
            <?php

            use App\Scheduler\SchedulerTaskDefinition;

            return SchedulerTaskDefinition::command(
                'scheduler-module.cleanup',
                'pkg.scheduler_module.cleanup.label',
                'pkg.scheduler_module.cleanup.description',
                'demo:cleanup',
                '*/15 * * * *',
                'system',
                true,
            );
            PHP);
        $registry = new PackageRuntimeContributionRegistry();

        $result = (new PackagePhpLoader(
            new ActivePackageProvider($this->entityManager),
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
            runtimeContributions: $registry,
        ))->loadActivePackages();

        self::assertFalse($result->isSuccess());
        self::assertSame('package.lifecycle.php_load_failed', $result->firstIssue()?->code());
        self::assertSame('message.package.scheduler.source_invalid', $result->firstIssue()?->context()['previous_message']['key'] ?? null);
        self::assertSame([], $registry->schedulerTasks());
        self::assertSame('faulty', $this->packageStatus('scheduler-module'));
    }

    public function testPackagePhpLoaderKeepsSchedulerExecutionProviders(): void
    {
        $this->insertPackage('scheduler-module', ['module'], 'active');
        $this->writeTestFile($this->projectDir, 'packages/scheduler-module/package.php', <<<'PHP'
            <?php

            use App\Core\Operation\ActionQueue;
            use App\Scheduler\SchedulerActionQueueProviderInterface;
            use App\Scheduler\SchedulerCallableProviderInterface;
            use App\Scheduler\SchedulerTaskDefinition;
            use App\Scheduler\SchedulerTaskExecution;
            use App\Scheduler\SchedulerTaskProviderInterface;

            return new class implements SchedulerTaskProviderInterface, SchedulerCallableProviderInterface, SchedulerActionQueueProviderInterface {
                public function schedulerTasks(): array
                {
                    return [
                        new SchedulerTaskDefinition(
                            'scheduler-module.cleanup',
                            'pkg.scheduler_module.cleanup.label',
                            'pkg.scheduler_module.cleanup.description',
                            'scheduler-module',
                            \App\Scheduler\SchedulerTaskType::Callable,
                            'scheduler-module.cleanup',
                            '*/15 * * * *',
                            false,
                        ),
                    ];
                }

                public function schedulerCallable(string $target): ?callable
                {
                    return 'scheduler-module.cleanup' === $target
                        ? static fn (): SchedulerTaskExecution => SchedulerTaskExecution::success(['package_callable' => true])
                        : null;
                }

                public function schedulerActionQueue(string $target): ?ActionQueue
                {
                    return 'scheduler-module.queue' === $target ? ActionQueue::create('scheduler-module.queue') : null;
                }
            };
            PHP);
        $registry = new PackageRuntimeContributionRegistry();

        $result = (new PackagePhpLoader(
            new ActivePackageProvider($this->entityManager),
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
            runtimeContributions: $registry,
        ))->loadActivePackages();

        self::assertTrue($result->isSuccess());
        self::assertSame('scheduler-module.cleanup', $registry->schedulerTasks()[0]->identifier());
        self::assertNotNull($registry->schedulerCallable('scheduler-module.cleanup'));
        self::assertNull($registry->schedulerCallable('scheduler-module.missing'));
        self::assertSame('scheduler-module.queue', $registry->schedulerActionQueue('scheduler-module.queue')?->name());
        self::assertNull($registry->schedulerActionQueue('scheduler-module.missing'));
    }

    public function testPackagePhpLoaderConvertsRuntimeProviderFailuresIntoFaults(): void
    {
        $this->insertPackage('broken-provider-module', ['module'], 'active');
        $messageBus = new RecordingMessageBus();
        $this->writeTestFile($this->projectDir, 'packages/broken-provider-module/package.php', <<<'PHP'
            <?php

            use App\View\Injection\StaticViewInjectionProviderInterface;

            return new class implements StaticViewInjectionProviderInterface {
                public function staticViewInjections(): array
                {
                    throw new RuntimeException('broken static provider');
                }
            };
            PHP);
        $registry = new PackageRuntimeContributionRegistry();

        $result = (new PackagePhpLoader(
            new ActivePackageProvider($this->entityManager),
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
            new PackageAssetRebuildDispatcher($messageBus, new NullWorkflowResultMessageReporter()),
            'test',
            runtimeContributions: $registry,
        ))->loadActivePackages();

        self::assertFalse($result->isSuccess());
        self::assertSame('package.lifecycle.php_load_failed', $result->firstIssue()?->code());
        self::assertSame([], $registry->staticViewInjections());
        self::assertSame('faulty', $this->packageStatus('broken-provider-module'));
    }

    public function testPackagePhpLoaderCanReloadPackagePhpAcrossLoaderInstances(): void
    {
        $this->insertPackage('demo-module', ['module'], 'active');
        $this->writeTestFile($this->projectDir, 'packages/demo-module/package.php', <<<'PHP'
            <?php

            use App\View\Injection\StaticViewInjection;
            use App\View\Injection\ViewSurface;

            return new StaticViewInjection(
                'pkg-demo-module-reload',
                ViewSurface::Public,
                'demo-module',
                'pkg.demo-module.widget',
                '@frontend/demo-module/frontend.html.twig',
            );
            PHP);

        $firstRegistry = new PackageRuntimeContributionRegistry();
        $secondRegistry = new PackageRuntimeContributionRegistry();

        $firstResult = (new PackagePhpLoader(
            new ActivePackageProvider($this->entityManager),
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
            runtimeContributions: $firstRegistry,
        ))->loadActivePackages();
        $secondResult = (new PackagePhpLoader(
            new ActivePackageProvider($this->entityManager),
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
            runtimeContributions: $secondRegistry,
        ))->loadActivePackages();

        self::assertTrue($firstResult->isSuccess());
        self::assertTrue($secondResult->isSuccess());
        self::assertSame('pkg-demo-module-reload', $firstRegistry->staticViewInjections()[0]->uid());
        self::assertSame('pkg-demo-module-reload', $secondRegistry->staticViewInjections()[0]->uid());
    }

    public function testPackagePhpLoaderMarksFailingPackagesFaulty(): void
    {
        $this->insertPackage('broken-module', ['module'], 'active');
        $this->insertPackage('broken-addon', ['module'], 'active', dependencies: '[["broken-module", "1.0.0"]]');
        $messageBus = new RecordingMessageBus();
        $this->writeTestFile($this->projectDir, 'packages/broken-module/package.php', <<<'PHP'
            <?php

            throw new RuntimeException('broken package loader');
            PHP);
        $this->writeTestFile($this->projectDir, 'packages/broken-addon/package.php', <<<'PHP'
            <?php

            file_put_contents(__DIR__.'/loaded.txt', 'yes');
            PHP);

        $result = (new PackagePhpLoader(
            new ActivePackageProvider($this->entityManager),
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
            new PackageAssetRebuildDispatcher($messageBus, new NullWorkflowResultMessageReporter()),
            'test',
        ))->loadActivePackages();

        self::assertFalse($result->isSuccess());
        self::assertSame('package.lifecycle.php_load_failed', $result->firstIssue()?->code());
        self::assertSame('faulty', $this->packageStatus('broken-module'));
        self::assertSame('inactive', $this->packageStatus('broken-addon'));
        self::assertFileDoesNotExist($this->projectDir.'/packages/broken-addon/loaded.txt');
        self::assertSame('RuntimeException', $this->metadata('broken-module')['runtime_loader']['exception']);
        self::assertContains('message.package.lifecycle.dependent_deactivated', array_map(
            static fn (Message $message): string => $message->translationKey(),
            $result->messages(),
        ));
        self::assertCount(1, $messageBus->messages());
        self::assertInstanceOf(PackageAssetRebuildMessage::class, $messageBus->messages()[0]);
        self::assertSame('package_php_loader_faulty', $messageBus->messages()[0]->trigger());
    }

    public function testPackageAssetRebuildMessageHandlerRunsLifecycleRebuild(): void
    {
        $result = (new PackageAssetRebuildMessageHandler($this->assetRebuilder))(new PackageAssetRebuildMessage('test', 'faulty'));

        self::assertTrue($result->isSuccess());
        self::assertSame(['test'], $this->assetRebuilder->environments);
    }

    public function testPackageRemoverDeletesPackageDirectoryAndMarksRegistryRemoved(): void
    {
        $this->insertPackage('demo-module', ['module'], 'active');
        $this->writeTestFile($this->projectDir, 'packages/demo-module/.manifest', 'PACKAGE_NAME=Demo');
        $this->writeTestFile($this->projectDir, 'packages/demo-module/src/Demo.php', '<?php');

        $result = $this->remover()->remove('demo-module', 'test');

        self::assertTrue($result->isSuccess());
        self::assertDirectoryDoesNotExist($this->projectDir.'/packages/demo-module');
        self::assertSame('removed', $this->packageStatus('demo-module'));
        self::assertSame(['test'], $this->assetRebuilder->environments);
        self::assertSame([], $this->cleanupRunner->packages);
        self::assertSame('removed', $this->metadata('demo-module')['registry_state']);
        self::assertSame(['demo-module', 'demo-module'], array_column($result->value()['changes'], 'package'));
    }

    public function testPackageRemoverDeactivatesActiveDependentsBeforeDeletingPackage(): void
    {
        $this->insertPackage('demo-module', ['module'], 'active');
        $this->insertPackage('demo-addon', ['module'], 'active', dependencies: '[["demo-module", "1.0.0"]]');
        $this->writeTestFile($this->projectDir, 'packages/demo-module/.manifest', 'PACKAGE_NAME=Demo');

        $plan = $this->remover()->planRemoval('demo-module');
        self::assertTrue($plan->isSuccess());
        self::assertSame(['demo-addon', 'demo-module', 'demo-module'], array_column($plan->value()['changes'], 'package'));

        $result = $this->remover()->remove('demo-module', 'test');

        self::assertTrue($result->isSuccess(), json_encode($result->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame('removed', $this->packageStatus('demo-module'));
        self::assertSame('inactive', $this->packageStatus('demo-addon'));
        self::assertSame(['test'], $this->assetRebuilder->environments);
        self::assertSame(['demo-addon', 'demo-module', 'demo-module'], array_column($result->value()['changes'], 'package'));
    }

    public function testPackageRemoverPurgeRunsCleanupAndDeletesRegistryRow(): void
    {
        $this->insertPackage('demo-module', ['module'], 'removed');

        $result = $this->remover()->purge('demo-module');

        self::assertTrue($result->isSuccess());
        self::assertSame(['demo-module'], $this->cleanupRunner->packages);
        self::assertFalse($this->packageRowExists('demo-module'));
        self::assertSame('purged', $result->value()['changes'][0]['action']);
    }

    public function testPackageRemoverPurgeBlocksNonRemovedPackages(): void
    {
        $this->insertPackage('demo-module', ['module'], 'active');

        $result = $this->remover()->purge('demo-module');

        self::assertFalse($result->isSuccess());
        self::assertSame('package.lifecycle.status_blocked', $result->firstIssue()?->code());
        self::assertSame([], $this->cleanupRunner->packages);
        self::assertTrue($this->packageRowExists('demo-module'));
        self::assertSame('active', $this->packageStatus('demo-module'));
    }

    public function testPackageFaultResetterReturnsValidatedFaultyPackageToInactive(): void
    {
        $this->insertPackage('demo-module', ['module'], 'faulty');
        $this->writePackageManifest('demo-module');

        $result = (new PackageFaultResetter(
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
            'test',
        ))->resetFault('demo-module');

        self::assertTrue($result->isSuccess());
        self::assertSame('inactive', $this->packageStatus('demo-module'));
        self::assertSame('fault_reset', $result->value()['changes'][0]['action']);
        self::assertSame('package.lifecycle.fault_reset', $result->messages()[1]->code());
        self::assertSame('available', $this->metadata('demo-module')['registry_state']);
        self::assertSame('Lifecycle boundary demo package.', $this->metadata('demo-module')['description']);
        self::assertArrayHasKey('last_fault', $this->metadata('demo-module'));
    }

    public function testPackageFaultResetterKeepsInvalidPackageFaulty(): void
    {
        $this->insertPackage('demo-module', ['module'], 'faulty');
        $this->writePackageManifest('demo-module');
        $this->writeTestFile($this->projectDir, 'packages/demo-module/src/Broken.php', <<<'PHP'
            <?php

            final class Broken {
            PHP);

        $result = (new PackageFaultResetter(
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
            'test',
        ))->resetFault('demo-module');

        self::assertFalse($result->isSuccess());
        self::assertSame('package.php_syntax_error', $result->firstIssue()?->code());
        self::assertSame('faulty', $this->packageStatus('demo-module'));
    }

    public function testPackageFaultResetterBlocksNonFaultyPackage(): void
    {
        $this->insertPackage('demo-module', ['module'], 'inactive');
        $this->writePackageManifest('demo-module');

        $result = (new PackageFaultResetter(
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
            'test',
        ))->resetFault('demo-module');

        self::assertFalse($result->isSuccess());
        self::assertSame('package.lifecycle.status_blocked', $result->firstIssue()?->code());
        self::assertSame('inactive', $this->packageStatus('demo-module'));
    }

    /**
     * @param list<string> $scopes
     */
    private function insertPackage(
        string $packageName,
        array $scopes,
        string $status,
        string $path = '',
        string $dependencies = '[]',
    ): void {
        $this->connection->insert('extension_package', [
            'uid' => $this->uuid(),
            'package_scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
            'package_name' => $packageName,
            'path' => '' === $path ? 'packages/'.$packageName : $path,
            'manifest_version' => '1.0.0',
            'installed_version' => '1.0.0',
            'status' => $status,
            'metadata' => json_encode([
                'registry_state' => 'available',
                'manifest' => [
                    'PACKAGE_DEPENDENCIES' => $dependencies,
                ],
            ], JSON_THROW_ON_ERROR),
            'modified_at' => '2026-05-25 00:00:00',
        ]);
    }

    private function remover(): PackageRemover
    {
        $reporter = new NullWorkflowResultMessageReporter();

        return new PackageRemover(
            $this->entityManager,
            new PackageActivator($this->entityManager, $this->assetRebuilder, $reporter),
            $this->assetRebuilder,
            $this->cleanupRunner,
            $this->projectDir,
            $reporter,
        );
    }

    private function packageStatus(string $packageName): string
    {
        $status = $this->connection->fetchOne(
            'SELECT status FROM extension_package WHERE package_name = :package_name',
            ['package_name' => $packageName],
        );

        self::assertIsString($status);

        return $status;
    }

    private function writePackageManifest(string $packageName): void
    {
        $this->writeTestFile($this->projectDir, 'packages/'.$packageName.'/.manifest', <<<'MANIFEST'
            PACKAGE_AUTHOR=Aavion Test Fixtures
            PACKAGE_SLUG=demo-module
            PACKAGE_NAME=Demo Module
            PACKAGE_DESCRIPTION=Lifecycle boundary demo package.
            PACKAGE_VERSION=1.0.1
            PACKAGE_SCOPE=module
            PACKAGE_DEPENDENCIES=[]
            MANIFEST);
    }

    private function packageRowExists(string $packageName): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM extension_package WHERE package_name = :package_name',
            ['package_name' => $packageName],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(string $packageName): array
    {
        $metadata = $this->connection->fetchOne(
            'SELECT metadata FROM extension_package WHERE package_name = :package_name',
            ['package_name' => $packageName],
        );

        self::assertIsString($metadata);
        $decoded = json_decode($metadata, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function uuid(): string
    {
        return Uuid::v7()->toRfc4122();
    }
}

final class RecordingPackageLifecycleCleanupRunner implements PackageLifecycleCleanupRunnerInterface
{
    /**
     * @var list<string>
     */
    public array $packages = [];

    public function cleanup(ExtensionPackage $package): WorkflowResult
    {
        $this->packages[] = $package->packageName();

        return WorkflowResult::success([
            'package' => $package->packageName(),
            'actions' => [],
        ], [
            'package' => $package->packageName(),
            'actions' => [],
        ]);
    }
}

final class BoundaryPackageLifecycleAssetRebuilder implements PackageLifecycleAssetRebuilderInterface
{
    /**
     * @var list<string>
     */
    public array $environments = [];

    public function rebuild(string $environment): WorkflowResult
    {
        $this->environments[] = $environment;

        return WorkflowResult::success(context: ['fake' => true]);
    }
}
