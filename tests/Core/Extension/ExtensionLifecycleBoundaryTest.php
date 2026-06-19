<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Event\EventHookDescriptor;
use App\Core\Event\EventHookMode;
use App\Core\Event\EventMessageCode;
use App\Core\Event\EventMessageKey;
use App\Core\Event\PublicHookFailedEvent;
use App\Core\Message\Message;
use App\Core\Extension\ActiveExtensionProvider;
use App\Core\Extension\ExtensionActivator;
use App\Core\Extension\ExtensionAssetRebuildDispatcher;
use App\Core\Extension\ExtensionAssetRebuildMessage;
use App\Core\Extension\ExtensionAssetRebuildMessageHandler;
use App\Core\Extension\ExtensionFaultResetter;
use App\Core\Extension\ExtensionLifecycleAssetRebuilderInterface;
use App\Core\Extension\ExtensionLifecycleCleanupRunnerInterface;
use App\Core\Extension\ExtensionPhpLoader;
use App\Core\Extension\ExtensionRemover;
use App\Core\Extension\ExtensionRuntimeContributionRegistry;
use App\Core\Extension\ExtensionRuntimeFailureHandler;
use App\Core\Extension\ExtensionScope;
use App\Core\Workflow\WorkflowResult;
use App\Entity\Extension;
use App\Tests\Support\FilesystemTestHelper;
use App\Tests\Support\NullWorkflowResultMessageReporter;
use App\Tests\Support\RecordingMessageBus;
use App\View\ViewContextEvent;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ExtensionLifecycleBoundaryTest extends KernelTestCase
{
    use FilesystemTestHelper;

    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private BoundaryExtensionLifecycleAssetRebuilder $assetRebuilder;
    private RecordingExtensionLifecycleCleanupRunner $cleanupRunner;
    private string $projectDir;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->assetRebuilder = new BoundaryExtensionLifecycleAssetRebuilder();
        $this->cleanupRunner = new RecordingExtensionLifecycleCleanupRunner();
        $this->projectDir = $this->createTemporaryDirectory('system-extension-lifecycle-boundary');
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

    public function testActiveExtensionProviderReturnsOnlyActiveRealExtensions(): void
    {
        $this->insertExtension('demo-module', ['module'], 'active');
        $this->insertExtension('demo-theme', ['frontend-theme'], 'active');
        $this->insertExtension('inactive-module', ['module'], 'inactive');
        $this->insertExtension('system-local', ['system-template'], 'active', path: '.');

        $provider = new ActiveExtensionProvider($this->entityManager);

        self::assertSame(['demo-module', 'demo-theme'], array_map(
            static fn (Extension $extension): string => $extension->extensionName(),
            $provider->extensions(),
        ));
        self::assertSame(['demo-module'], array_map(
            static fn (Extension $extension): string => $extension->extensionName(),
            $provider->extensions(ExtensionScope::Module),
        ));
        self::assertSame('demo-theme', $provider->extension('demo-theme')?->extensionName());
        self::assertNull($provider->extension('inactive-module'));
    }

    public function testRuntimeHookFailureMarksIdentifiedActiveExtensionFaulty(): void
    {
        $this->insertExtension('demo-module', ['module'], 'active');
        $this->insertExtension('demo-addon', ['module'], 'active', dependencies: '[["demo-module", "1.0.0"]]');

        $messageBus = new RecordingMessageBus();

        $result = (new ExtensionRuntimeFailureHandler(
            $this->entityManager,
            new NullWorkflowResultMessageReporter(),
            new ExtensionAssetRebuildDispatcher($messageBus, new NullWorkflowResultMessageReporter()),
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
        self::assertSame('faulty', $this->extensionStatus('demo-module'));
        self::assertSame('inactive', $this->extensionStatus('demo-addon'));
        self::assertSame(['demo-addon'], $result->value()['deactivated_dependents']);
        self::assertSame(ViewContextEvent::class, $this->metadata('demo-module')['runtime_failure']['hook']);
        self::assertSame('faulty', $this->metadata('demo-module')['registry_state']);
        self::assertContains('message.extension.lifecycle.dependent_deactivated', array_map(
            static fn (Message $message): string => $message->translationKey(),
            $result->messages(),
        ));
        self::assertCount(1, $messageBus->messages());
        self::assertInstanceOf(ExtensionAssetRebuildMessage::class, $messageBus->messages()[0]);
        self::assertSame('test', $messageBus->messages()[0]->environment());
        self::assertSame('extension_runtime_failure', $messageBus->messages()[0]->trigger());
    }

    public function testExtensionPhpLoaderIncludesOnlyActiveExtensionLoaders(): void
    {
        $this->insertExtension('demo-module', ['module'], 'active');
        $this->insertExtension('inactive-module', ['module'], 'inactive');
        $this->writeTestFile($this->projectDir, 'extensions/demo-module/extension.php', <<<'PHP'
            <?php

            file_put_contents(__DIR__.'/loaded.txt', 'yes');

            return static function ($extension): void {
                file_put_contents(__DIR__.'/called.txt', $extension->extensionName());
            };
            PHP);
        $this->writeTestFile($this->projectDir, 'extensions/inactive-module/extension.php', <<<'PHP'
            <?php

            file_put_contents(__DIR__.'/loaded.txt', 'no');
            PHP);

        $result = (new ExtensionPhpLoader(
            new ActiveExtensionProvider($this->entityManager),
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
        ))->loadActiveExtensions();

        self::assertTrue($result->isSuccess());
        self::assertSame(['demo-module'], $result->value()['loaded']);
        self::assertFileExists($this->projectDir.'/extensions/demo-module/loaded.txt');
        self::assertSame('demo-module', file_get_contents($this->projectDir.'/extensions/demo-module/called.txt'));
        self::assertFileDoesNotExist($this->projectDir.'/extensions/inactive-module/loaded.txt');
    }

    public function testExtensionPhpLoaderRegistersRuntimeContributions(): void
    {
        $this->insertExtension('demo-module', ['module'], 'active');
        $this->writeTestFile($this->projectDir, 'extensions/demo-module/extension.php', <<<'PHP'
            <?php

            use App\Api\Endpoint\ApiEndpointDefinition;
            use App\Api\Endpoint\ApiEndpointHandlerInterface;
            use App\Api\Endpoint\ExtensionApiEndpointPath;
            use App\Core\Extension\ExtensionContributions;
            use App\Core\Extension\Settings\ExtensionSettingDefinition;
            use App\View\Injection\ConfigurableStaticViewInjectionRoute;
            use App\View\Injection\ConfigurableStaticViewInjectionSet;
            use App\View\Injection\DynamicViewInjection;
            use App\View\Injection\DynamicViewInjectionSlot;
            use App\View\Injection\StaticViewInjection;
            use App\View\Injection\ViewSurface;
            use Symfony\Component\HttpFoundation\JsonResponse;
            use Symfony\Component\HttpFoundation\Request;
            use Symfony\Component\HttpFoundation\Response;

            return ExtensionContributions::create()
                ->staticView(new StaticViewInjection(
                    'ext-demo-module-route',
                    ViewSurface::Public,
                    'demo-module',
                    'ext.demo-module.widget',
                    '@frontend/demo-module/frontend.html.twig',
                ))
                ->configurableStaticViews(new ConfigurableStaticViewInjectionSet(
                    'demo-module',
                    'demo.route',
                    ViewSurface::Public,
                    'demo',
                    [
                        new ConfigurableStaticViewInjectionRoute(
                            'ext-demo-module-configurable-route',
                            '',
                            'ext.demo-module.widget',
                            '@frontend/demo-module/frontend.html.twig',
                        ),
                    ],
                ))
                ->dynamicView(new DynamicViewInjection(
                    'ext-demo-module-after-content',
                    ViewSurface::Public,
                    DynamicViewInjectionSlot::AfterContent,
                    '@frontend/demo-module/after-content.html.twig',
                ))
                ->setting(new ExtensionSettingDefinition(
                    'demo-module',
                    'display.mode',
                    'ext.demo-module.settings.display_mode.label',
                    'compact',
                ))
                ->apiEndpoint(new ApiEndpointDefinition(
                    'extension',
                    'GET',
                    ExtensionApiEndpointPath::path($extension->extensionName(), 'demo'),
                    'api_v1_endpoint_dispatch',
                    'getDemoModuleExtensionEndpoint',
                    'Return extension demo data.',
                    'extensions.demo-module.demo',
                    ['extensions-demo-module-demo'],
                    responseSchema: ['type' => 'object'],
                ))
                ->apiEndpointHandler(new class implements ApiEndpointHandlerInterface {
                    public function apiEndpointHandlerKey(): string
                    {
                        return 'extensions.demo-module.demo';
                    }

                    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response
                    {
                        return new JsonResponse(['data' => ['type' => 'extension_demo']]);
                    }
                });
            PHP);
        $registry = new ExtensionRuntimeContributionRegistry();

        $result = (new ExtensionPhpLoader(
            new ActiveExtensionProvider($this->entityManager),
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
            runtimeContributions: $registry,
        ))->loadActiveExtensions();

        self::assertTrue($result->isSuccess());
        self::assertSame('ext-demo-module-route', $registry->staticViewInjections()[0]->uid());
        self::assertSame('demo-module', $registry->staticViewInjections()[0]->pathSlug());
        self::assertSame('ext-demo-module-configurable-route', $registry->staticViewInjections()[1]->uid());
        self::assertSame('demo', $registry->staticViewInjections()[1]->pathSlug());
        self::assertSame('ext-demo-module-after-content', $registry->dynamicViewInjections()[0]->uid());
        self::assertSame('display.mode', $registry->extensionSettings()[0]->key());
        self::assertSame('/api/v1/extensions/demo-module/demo', $registry->apiEndpoints()[0]->path());
        self::assertSame('extensions.demo-module.demo', $registry->apiEndpointHandlers()[0]->apiEndpointHandlerKey());
    }

    public function testExtensionPhpLoaderDoesNotKeepPartialRuntimeContributionsAfterFailure(): void
    {
        $this->insertExtension('broken-module', ['module'], 'active');
        $messageBus = new RecordingMessageBus();
        $this->writeTestFile($this->projectDir, 'extensions/broken-module/extension.php', <<<'PHP'
            <?php

            use App\View\Injection\StaticViewInjection;
            use App\View\Injection\ViewSurface;

            return [
                new StaticViewInjection(
                    'ext-broken-module-route',
                    ViewSurface::Public,
                    'broken-module',
                    'ext.broken-module.widget',
                    '@frontend/broken-module/frontend.html.twig',
                ),
                new stdClass(),
            ];
            PHP);
        $registry = new ExtensionRuntimeContributionRegistry();

        $result = (new ExtensionPhpLoader(
            new ActiveExtensionProvider($this->entityManager),
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
            new ExtensionAssetRebuildDispatcher($messageBus, new NullWorkflowResultMessageReporter()),
            'test',
            runtimeContributions: $registry,
        ))->loadActiveExtensions();

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.lifecycle.php_load_failed', $result->firstIssue()?->code());
        self::assertSame('message.extension.runtime.contribution_unsupported', $result->firstIssue()?->context()['previous_message']['key'] ?? null);
        self::assertSame([], $registry->staticViewInjections());
        self::assertSame('faulty', $this->extensionStatus('broken-module'));
    }

    public function testExtensionPhpLoaderAcceptsScopedNecessaryCookieConsentContributions(): void
    {
        $this->insertExtension('captcha-provider', ['captcha-provider'], 'active');
        $this->writeTestFile($this->projectDir, 'extensions/captcha-provider/extension.php', <<<'PHP'
            <?php

            use App\Privacy\Cookie\CookieConsentDefinition;
            use Symfony\Component\HttpFoundation\Cookie;

            return CookieConsentDefinition::necessary(Cookie::create('captcha_provider_state'));
            PHP);
        $registry = new ExtensionRuntimeContributionRegistry();

        $result = (new ExtensionPhpLoader(
            new ActiveExtensionProvider($this->entityManager),
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
            runtimeContributions: $registry,
        ))->loadActiveExtensions();

        self::assertTrue($result->isSuccess());
        self::assertSame('captcha_provider_state', $registry->cookieConsentDefinitions()[0]->name());
    }

    public function testExtensionPhpLoaderRejectsUnscopedNecessaryCookieConsentContributions(): void
    {
        $this->insertExtension('tracking-module', ['module'], 'active');
        $this->writeTestFile($this->projectDir, 'extensions/tracking-module/extension.php', <<<'PHP'
            <?php

            use App\Privacy\Cookie\CookieConsentDefinition;
            use Symfony\Component\HttpFoundation\Cookie;

            return CookieConsentDefinition::necessary(Cookie::create('analytics_id'));
            PHP);
        $registry = new ExtensionRuntimeContributionRegistry();

        $result = (new ExtensionPhpLoader(
            new ActiveExtensionProvider($this->entityManager),
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
            runtimeContributions: $registry,
        ))->loadActiveExtensions();

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.lifecycle.php_load_failed', $result->firstIssue()?->code());
        self::assertSame('message.extension.runtime.contribution_unsupported', $result->firstIssue()?->context()['previous_message']['key'] ?? null);
        self::assertSame([], $registry->cookieConsentDefinitions());
        self::assertSame('faulty', $this->extensionStatus('tracking-module'));
    }

    public function testExtensionPhpLoaderRejectsCrossSiteNecessaryCookieConsentContributions(): void
    {
        $this->insertExtension('captcha-provider', ['captcha-provider'], 'active');
        $this->writeTestFile($this->projectDir, 'extensions/captcha-provider/extension.php', <<<'PHP'
            <?php

            use App\Privacy\Cookie\CookieConsentDefinition;
            use Symfony\Component\HttpFoundation\Cookie;

            return CookieConsentDefinition::necessary(Cookie::create(
                'captcha_provider_state',
                domain: '.example.test',
                sameSite: Cookie::SAMESITE_NONE,
            ));
            PHP);
        $registry = new ExtensionRuntimeContributionRegistry();

        $result = (new ExtensionPhpLoader(
            new ActiveExtensionProvider($this->entityManager),
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
            runtimeContributions: $registry,
        ))->loadActiveExtensions();

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.lifecycle.php_load_failed', $result->firstIssue()?->code());
        self::assertSame('message.extension.runtime.contribution_unsupported', $result->firstIssue()?->context()['previous_message']['key'] ?? null);
        self::assertSame([], $registry->cookieConsentDefinitions());
        self::assertSame('faulty', $this->extensionStatus('captcha-provider'));
    }

    public function testExtensionPhpLoaderRejectsDuplicateCookieConsentContributions(): void
    {
        $this->insertExtension('cookie-module', ['module'], 'active');
        $this->writeTestFile($this->projectDir, 'extensions/cookie-module/extension.php', <<<'PHP'
            <?php

            use App\Privacy\Cookie\CookieConsentDefinition;
            use Symfony\Component\HttpFoundation\Cookie;

            return [
                CookieConsentDefinition::optional(
                    Cookie::create('cookie_module_tracking'),
                    'Tracking',
                    'Measure visits.',
                    'https://example.test/privacy',
                ),
                CookieConsentDefinition::optional(
                    Cookie::create('cookie_module_tracking'),
                    'Tracking',
                    'Duplicate.',
                    'https://example.test/privacy',
                ),
            ];
            PHP);
        $registry = new ExtensionRuntimeContributionRegistry();

        $result = (new ExtensionPhpLoader(
            new ActiveExtensionProvider($this->entityManager),
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
            runtimeContributions: $registry,
        ))->loadActiveExtensions();

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.lifecycle.php_load_failed', $result->firstIssue()?->code());
        self::assertSame('message.extension.runtime.contribution_unsupported', $result->firstIssue()?->context()['previous_message']['key'] ?? null);
        self::assertSame([], $registry->cookieConsentDefinitions());
        self::assertSame('faulty', $this->extensionStatus('cookie-module'));
    }

    public function testExtensionPhpLoaderRejectsReservedCoreCookieConsentContributions(): void
    {
        $this->insertExtension('session-module', ['module'], 'active');
        $this->writeTestFile($this->projectDir, 'extensions/session-module/extension.php', <<<'PHP'
            <?php

            use App\Privacy\Cookie\CookieConsentDefinition;
            use Symfony\Component\HttpFoundation\Cookie;

            return CookieConsentDefinition::optional(
                Cookie::create('PHPSESSID'),
                'Session Module',
                'Override the core session cookie.',
                'https://example.test/privacy',
            );
            PHP);
        $registry = new ExtensionRuntimeContributionRegistry();

        $result = (new ExtensionPhpLoader(
            new ActiveExtensionProvider($this->entityManager),
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
            runtimeContributions: $registry,
        ))->loadActiveExtensions();

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.lifecycle.php_load_failed', $result->firstIssue()?->code());
        self::assertSame('message.extension.runtime.contribution_unsupported', $result->firstIssue()?->context()['previous_message']['key'] ?? null);
        self::assertSame([], $registry->cookieConsentDefinitions());
        self::assertSame('faulty', $this->extensionStatus('session-module'));
    }

    public function testExtensionPhpLoaderRejectsUnsafeOptionalCookiePrivacyUrls(): void
    {
        $this->insertExtension('tracking-module', ['module'], 'active');
        $this->writeTestFile($this->projectDir, 'extensions/tracking-module/extension.php', <<<'PHP'
            <?php

            use App\Privacy\Cookie\CookieConsentDefinition;
            use Symfony\Component\HttpFoundation\Cookie;

            return CookieConsentDefinition::optional(
                Cookie::create('tracking_module_id'),
                'Tracking',
                'Measure visits.',
                'javascript:alert(1)',
            );
            PHP);
        $registry = new ExtensionRuntimeContributionRegistry();

        $result = (new ExtensionPhpLoader(
            new ActiveExtensionProvider($this->entityManager),
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
            runtimeContributions: $registry,
        ))->loadActiveExtensions();

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.lifecycle.php_load_failed', $result->firstIssue()?->code());
        self::assertSame(InvalidArgumentException::class, $result->firstIssue()?->context()['exception'] ?? null);
        self::assertSame([], $registry->cookieConsentDefinitions());
        self::assertSame('faulty', $this->extensionStatus('tracking-module'));
    }

    public function testExtensionPhpLoaderRejectsElevatedSchedulerContributions(): void
    {
        $this->insertExtension('scheduler-module', ['module'], 'active');
        $this->writeTestFile($this->projectDir, 'extensions/scheduler-module/extension.php', <<<'PHP'
            <?php

            use App\Scheduler\SchedulerTaskDefinition;

            return SchedulerTaskDefinition::command(
                'scheduler-module.cleanup',
                'ext.scheduler_module.cleanup.label',
                'ext.scheduler_module.cleanup.description',
                'demo:cleanup',
                '*/15 * * * *',
                'system',
                true,
            );
            PHP);
        $registry = new ExtensionRuntimeContributionRegistry();

        $result = (new ExtensionPhpLoader(
            new ActiveExtensionProvider($this->entityManager),
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
            runtimeContributions: $registry,
        ))->loadActiveExtensions();

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.lifecycle.php_load_failed', $result->firstIssue()?->code());
        self::assertSame('message.extension.scheduler.source_invalid', $result->firstIssue()?->context()['previous_message']['key'] ?? null);
        self::assertSame([], $registry->schedulerTasks());
        self::assertSame('faulty', $this->extensionStatus('scheduler-module'));
    }

    public function testExtensionPhpLoaderKeepsSchedulerExecutionProviders(): void
    {
        $this->insertExtension('scheduler-module', ['module'], 'active');
        $this->writeTestFile($this->projectDir, 'extensions/scheduler-module/extension.php', <<<'PHP'
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
                            'ext.scheduler_module.cleanup.label',
                            'ext.scheduler_module.cleanup.description',
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
                        ? static fn (): SchedulerTaskExecution => SchedulerTaskExecution::success(['extension_callable' => true])
                        : null;
                }

                public function schedulerActionQueue(string $target): ?ActionQueue
                {
                    return 'scheduler-module.queue' === $target ? ActionQueue::create('scheduler-module.queue') : null;
                }
            };
            PHP);
        $registry = new ExtensionRuntimeContributionRegistry();

        $result = (new ExtensionPhpLoader(
            new ActiveExtensionProvider($this->entityManager),
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
            runtimeContributions: $registry,
        ))->loadActiveExtensions();

        self::assertTrue($result->isSuccess());
        self::assertSame('scheduler-module.cleanup', $registry->schedulerTasks()[0]->identifier());
        self::assertNotNull($registry->schedulerCallable('scheduler-module.cleanup'));
        self::assertNull($registry->schedulerCallable('scheduler-module.missing'));
        self::assertSame('scheduler-module.queue', $registry->schedulerActionQueue('scheduler-module.queue')?->name());
        self::assertNull($registry->schedulerActionQueue('scheduler-module.missing'));
    }

    public function testExtensionPhpLoaderConvertsRuntimeProviderFailuresIntoFaults(): void
    {
        $this->insertExtension('broken-provider-module', ['module'], 'active');
        $messageBus = new RecordingMessageBus();
        $this->writeTestFile($this->projectDir, 'extensions/broken-provider-module/extension.php', <<<'PHP'
            <?php

            use App\View\Injection\StaticViewInjectionProviderInterface;

            return new class implements StaticViewInjectionProviderInterface {
                public function staticViewInjections(): array
                {
                    throw new RuntimeException('broken static provider');
                }
            };
            PHP);
        $registry = new ExtensionRuntimeContributionRegistry();

        $result = (new ExtensionPhpLoader(
            new ActiveExtensionProvider($this->entityManager),
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
            new ExtensionAssetRebuildDispatcher($messageBus, new NullWorkflowResultMessageReporter()),
            'test',
            runtimeContributions: $registry,
        ))->loadActiveExtensions();

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.lifecycle.php_load_failed', $result->firstIssue()?->code());
        self::assertSame([], $registry->staticViewInjections());
        self::assertSame('faulty', $this->extensionStatus('broken-provider-module'));
    }

    public function testExtensionPhpLoaderCanReloadExtensionPhpAcrossLoaderInstances(): void
    {
        $this->insertExtension('demo-module', ['module'], 'active');
        $this->writeTestFile($this->projectDir, 'extensions/demo-module/extension.php', <<<'PHP'
            <?php

            use App\View\Injection\StaticViewInjection;
            use App\View\Injection\ViewSurface;

            return new StaticViewInjection(
                'ext-demo-module-reload',
                ViewSurface::Public,
                'demo-module',
                'ext.demo-module.widget',
                '@frontend/demo-module/frontend.html.twig',
            );
            PHP);

        $firstRegistry = new ExtensionRuntimeContributionRegistry();
        $secondRegistry = new ExtensionRuntimeContributionRegistry();

        $firstResult = (new ExtensionPhpLoader(
            new ActiveExtensionProvider($this->entityManager),
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
            runtimeContributions: $firstRegistry,
        ))->loadActiveExtensions();
        $secondResult = (new ExtensionPhpLoader(
            new ActiveExtensionProvider($this->entityManager),
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
            runtimeContributions: $secondRegistry,
        ))->loadActiveExtensions();

        self::assertTrue($firstResult->isSuccess());
        self::assertTrue($secondResult->isSuccess());
        self::assertSame('ext-demo-module-reload', $firstRegistry->staticViewInjections()[0]->uid());
        self::assertSame('ext-demo-module-reload', $secondRegistry->staticViewInjections()[0]->uid());
    }

    public function testExtensionPhpLoaderMarksFailingExtensionsFaulty(): void
    {
        $this->insertExtension('broken-module', ['module'], 'active');
        $this->insertExtension('broken-addon', ['module'], 'active', dependencies: '[["broken-module", "1.0.0"]]');
        $messageBus = new RecordingMessageBus();
        $this->writeTestFile($this->projectDir, 'extensions/broken-module/extension.php', <<<'PHP'
            <?php

            throw new RuntimeException('broken extension loader');
            PHP);
        $this->writeTestFile($this->projectDir, 'extensions/broken-addon/extension.php', <<<'PHP'
            <?php

            file_put_contents(__DIR__.'/loaded.txt', 'yes');
            PHP);

        $result = (new ExtensionPhpLoader(
            new ActiveExtensionProvider($this->entityManager),
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
            new ExtensionAssetRebuildDispatcher($messageBus, new NullWorkflowResultMessageReporter()),
            'test',
        ))->loadActiveExtensions();

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.lifecycle.php_load_failed', $result->firstIssue()?->code());
        self::assertSame('faulty', $this->extensionStatus('broken-module'));
        self::assertSame('inactive', $this->extensionStatus('broken-addon'));
        self::assertFileDoesNotExist($this->projectDir.'/extensions/broken-addon/loaded.txt');
        self::assertSame('RuntimeException', $this->metadata('broken-module')['runtime_loader']['exception']);
        self::assertContains('message.extension.lifecycle.dependent_deactivated', array_map(
            static fn (Message $message): string => $message->translationKey(),
            $result->messages(),
        ));
        self::assertCount(1, $messageBus->messages());
        self::assertInstanceOf(ExtensionAssetRebuildMessage::class, $messageBus->messages()[0]);
        self::assertSame('extension_php_loader_faulty', $messageBus->messages()[0]->trigger());
    }

    public function testExtensionAssetRebuildMessageHandlerRunsLifecycleRebuild(): void
    {
        $result = (new ExtensionAssetRebuildMessageHandler($this->assetRebuilder))(new ExtensionAssetRebuildMessage('test', 'faulty'));

        self::assertTrue($result->isSuccess());
        self::assertSame(['test'], $this->assetRebuilder->environments);
    }

    public function testExtensionRemoverDeletesExtensionDirectoryAndMarksRegistryRemoved(): void
    {
        $this->insertExtension('demo-module', ['module'], 'active');
        $this->writeTestFile($this->projectDir, 'extensions/demo-module/.manifest', 'EXTENSION_NAME=Demo');
        $this->writeTestFile($this->projectDir, 'extensions/demo-module/src/Demo.php', '<?php');

        $result = $this->remover()->remove('demo-module', 'test');

        self::assertTrue($result->isSuccess());
        self::assertDirectoryDoesNotExist($this->projectDir.'/extensions/demo-module');
        self::assertSame('removed', $this->extensionStatus('demo-module'));
        self::assertSame(['test'], $this->assetRebuilder->environments);
        self::assertSame([], $this->cleanupRunner->extensions);
        self::assertSame('removed', $this->metadata('demo-module')['registry_state']);
        self::assertSame(['demo-module', 'demo-module'], array_column($result->value()['changes'], 'extension'));
    }

    public function testExtensionRemoverDeactivatesActiveDependentsBeforeDeletingExtension(): void
    {
        $this->insertExtension('demo-module', ['module'], 'active');
        $this->insertExtension('demo-addon', ['module'], 'active', dependencies: '[["demo-module", "1.0.0"]]');
        $this->writeTestFile($this->projectDir, 'extensions/demo-module/.manifest', 'EXTENSION_NAME=Demo');

        $plan = $this->remover()->planRemoval('demo-module');
        self::assertTrue($plan->isSuccess());
        self::assertSame(['demo-addon', 'demo-module', 'demo-module'], array_column($plan->value()['changes'], 'extension'));

        $result = $this->remover()->remove('demo-module', 'test');

        self::assertTrue($result->isSuccess(), json_encode($result->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame('removed', $this->extensionStatus('demo-module'));
        self::assertSame('inactive', $this->extensionStatus('demo-addon'));
        self::assertSame(['test'], $this->assetRebuilder->environments);
        self::assertSame(['demo-addon', 'demo-module', 'demo-module'], array_column($result->value()['changes'], 'extension'));
    }

    public function testExtensionRemoverPurgeRunsCleanupAndDeletesRegistryRow(): void
    {
        $this->insertExtension('demo-module', ['module'], 'removed');

        $result = $this->remover()->purge('demo-module');

        self::assertTrue($result->isSuccess());
        self::assertSame(['demo-module'], $this->cleanupRunner->extensions);
        self::assertFalse($this->extensionRowExists('demo-module'));
        self::assertSame('purged', $result->value()['changes'][0]['action']);
    }

    public function testExtensionRemoverPurgeBlocksNonRemovedExtensions(): void
    {
        $this->insertExtension('demo-module', ['module'], 'active');

        $result = $this->remover()->purge('demo-module');

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.lifecycle.status_blocked', $result->firstIssue()?->code());
        self::assertSame([], $this->cleanupRunner->extensions);
        self::assertTrue($this->extensionRowExists('demo-module'));
        self::assertSame('active', $this->extensionStatus('demo-module'));
    }

    public function testExtensionFaultResetterReturnsValidatedFaultyExtensionToInactive(): void
    {
        $this->insertExtension('demo-module', ['module'], 'faulty');
        $this->writeExtensionManifest('demo-module');

        $result = (new ExtensionFaultResetter(
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
            'test',
        ))->resetFault('demo-module');

        self::assertTrue($result->isSuccess());
        self::assertSame('inactive', $this->extensionStatus('demo-module'));
        self::assertSame('fault_reset', $result->value()['changes'][0]['action']);
        self::assertSame('extension.lifecycle.fault_reset', $result->messages()[1]->code());
        self::assertSame('available', $this->metadata('demo-module')['registry_state']);
        self::assertSame('Lifecycle boundary demo extension.', $this->metadata('demo-module')['description']);
        self::assertArrayHasKey('last_fault', $this->metadata('demo-module'));
    }

    public function testExtensionFaultResetterKeepsInvalidExtensionFaulty(): void
    {
        $this->insertExtension('demo-module', ['module'], 'faulty');
        $this->writeExtensionManifest('demo-module');
        $this->writeTestFile($this->projectDir, 'extensions/demo-module/src/Broken.php', <<<'PHP'
            <?php

            final class Broken {
            PHP);

        $result = (new ExtensionFaultResetter(
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
            'test',
        ))->resetFault('demo-module');

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.php_syntax_error', $result->firstIssue()?->code());
        self::assertSame('faulty', $this->extensionStatus('demo-module'));
    }

    public function testExtensionFaultResetterBlocksNonFaultyExtension(): void
    {
        $this->insertExtension('demo-module', ['module'], 'inactive');
        $this->writeExtensionManifest('demo-module');

        $result = (new ExtensionFaultResetter(
            $this->entityManager,
            $this->projectDir,
            new NullWorkflowResultMessageReporter(),
            'test',
        ))->resetFault('demo-module');

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.lifecycle.status_blocked', $result->firstIssue()?->code());
        self::assertSame('inactive', $this->extensionStatus('demo-module'));
    }

    /**
     * @param list<string> $scopes
     */
    private function insertExtension(
        string $extensionName,
        array $scopes,
        string $status,
        string $path = '',
        string $dependencies = '[]',
    ): void {
        $this->connection->insert('extension', [
            'uid' => $this->uuid(),
            'extension_scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
            'extension_name' => $extensionName,
            'path' => '' === $path ? 'extensions/'.$extensionName : $path,
            'manifest_version' => '1.0.0',
            'installed_version' => '1.0.0',
            'status' => $status,
            'metadata' => json_encode([
                'registry_state' => 'available',
                'manifest' => [
                    'EXTENSION_DEPENDENCIES' => $dependencies,
                ],
            ], JSON_THROW_ON_ERROR),
            'modified_at' => '2026-05-25 00:00:00',
        ]);
    }

    private function remover(): ExtensionRemover
    {
        $reporter = new NullWorkflowResultMessageReporter();

        return new ExtensionRemover(
            $this->entityManager,
            new ExtensionActivator($this->entityManager, $this->assetRebuilder, $reporter),
            $this->assetRebuilder,
            $this->cleanupRunner,
            $this->projectDir,
            $reporter,
        );
    }

    private function extensionStatus(string $extensionName): string
    {
        $status = $this->connection->fetchOne(
            'SELECT status FROM extension WHERE extension_name = :extension_name',
            ['extension_name' => $extensionName],
        );

        self::assertIsString($status);

        return $status;
    }

    private function writeExtensionManifest(string $extensionName): void
    {
        $this->writeTestFile($this->projectDir, 'extensions/'.$extensionName.'/.manifest', <<<'MANIFEST'
            EXTENSION_AUTHOR=Aavion Test Fixtures
            EXTENSION_SLUG=demo-module
            EXTENSION_NAME=Demo Module
            EXTENSION_DESCRIPTION=Lifecycle boundary demo extension.
            EXTENSION_VERSION=1.0.1
            EXTENSION_SCOPE=module
            EXTENSION_DEPENDENCIES=[]
            MANIFEST);
    }

    private function extensionRowExists(string $extensionName): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM extension WHERE extension_name = :extension_name',
            ['extension_name' => $extensionName],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(string $extensionName): array
    {
        $metadata = $this->connection->fetchOne(
            'SELECT metadata FROM extension WHERE extension_name = :extension_name',
            ['extension_name' => $extensionName],
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

final class RecordingExtensionLifecycleCleanupRunner implements ExtensionLifecycleCleanupRunnerInterface
{
    /**
     * @var list<string>
     */
    public array $extensions = [];

    public function cleanup(Extension $extension): WorkflowResult
    {
        $this->extensions[] = $extension->extensionName();

        return WorkflowResult::success([
            'extension' => $extension->extensionName(),
            'actions' => [],
        ], [
            'extension' => $extension->extensionName(),
            'actions' => [],
        ]);
    }
}

final class BoundaryExtensionLifecycleAssetRebuilder implements ExtensionLifecycleAssetRebuilderInterface
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
