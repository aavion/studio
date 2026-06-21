<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Core\Extension\Content\ExtensionContentSchemaDefinition;
use App\Core\Extension\Database\ExtensionDatabaseColumn;
use App\Core\Extension\Database\ExtensionDatabaseTable;
use App\Core\Extension\ExtensionContributionContext;
use App\Core\Extension\ExtensionActionQueueProviderInterface;
use App\Core\Extension\ExtensionContributions;
use App\Core\Extension\ExtensionEventListenerContribution;
use App\Core\Extension\ExtensionOperationDefinition;
use App\Core\Extension\ExtensionProviderContribution;
use App\Core\Extension\ExtensionRuntimeBoot;
use App\Core\Extension\ExtensionRuntimeContributionRegistry;
use App\Core\Extension\ExtensionScope;
use App\Core\Extension\ExtensionStatus;
use App\Core\Extension\Settings\ExtensionSettingDefinition;
use App\Core\Event\PublicEventInterface;
use App\Core\Operation\ActionQueue;
use App\Entity\Extension;
use App\Scheduler\SchedulerActionQueueProviderInterface;
use App\Scheduler\SchedulerCallableProviderInterface;
use App\Scheduler\SchedulerTaskDefinition;
use App\Scheduler\SchedulerTaskExecution;
use App\Scheduler\SchedulerTaskType;
use App\View\Injection\ConfigurableStaticViewInjectionRoute;
use App\View\Injection\ConfigurableStaticViewInjectionSet;
use App\View\Injection\DynamicViewInjection;
use App\View\Injection\DynamicViewInjectionSlot;
use App\View\Injection\StaticViewInjection;
use App\View\Injection\ViewSurface;
use App\View\ViewContextEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\Event;

final class ExtensionRuntimeContributionRegistryContractTest extends TestCase
{
    public function testItExpandsRuntimeContributionFactoriesWithExtensionContext(): void
    {
        $extension = $this->extension([ExtensionScope::Module]);
        $registry = new ExtensionRuntimeContributionRegistry();

        $registry->add($extension, ExtensionContributions::create()
            ->runtime(static fn (ExtensionContributionContext $context): array => [
                new ExtensionSettingDefinition(
                    $context->extensionName(),
                    'display.mode',
                    'extension.demo_module.display_mode.label',
                    'compact',
                ),
            ]));

        self::assertSame('demo-module', $registry->extensionSettings()[0]->extensionName());
        self::assertSame('display.mode', $registry->extensionSettings()[0]->key());
    }

    public function testItRejectsActivationFactoriesInRuntimeRegistryWithoutPartialState(): void
    {
        $extension = $this->extension([ExtensionScope::Module]);
        $registry = new ExtensionRuntimeContributionRegistry();

        try {
            $registry->add($extension, ExtensionContributions::create()
                ->setting(new ExtensionSettingDefinition(
                    'demo-module',
                    'display.mode',
                    'extension.demo_module.display_mode.label',
                    'compact',
                ))
                ->activation(static fn (): array => []));

            self::fail('Expected activation factories to be rejected by the runtime registry.');
        } catch (\Throwable $error) {
            self::assertStringContainsString('message.extension.runtime.contribution_unsupported', $error->getMessage());
        }

        self::assertSame([], $registry->extensionSettings());
    }

    public function testItRejectsRuntimeBootsInRuntimeRegistryWithoutPartialState(): void
    {
        $extension = $this->extension([ExtensionScope::Module]);
        $registry = new ExtensionRuntimeContributionRegistry();

        try {
            $registry->add($extension, [
                new ExtensionSettingDefinition(
                    'demo-module',
                    'display.mode',
                    'extension.demo_module.display_mode.label',
                    'compact',
                ),
                new ExtensionRuntimeBoot(static function (): void {
                }),
            ]);

            self::fail('Expected runtime boots to be handled by the extension loader before registry insertion.');
        } catch (\Throwable $error) {
            self::assertStringContainsString('message.extension.runtime.contribution_unsupported', $error->getMessage());
        }

        self::assertSame([], $registry->extensionSettings());
    }

    public function testItAcceptsDatabaseAndContentSchemaContributionsForMatchingScopes(): void
    {
        $extension = $this->extension([ExtensionScope::Module, ExtensionScope::Database, ExtensionScope::ContentSchema]);
        $table = ExtensionDatabaseTable::create('entry', [ExtensionDatabaseColumn::string('uid', 36)], ['uid']);
        $schema = ExtensionContentSchemaDefinition::create('article', ['en' => 'Article'], [
            'fields' => [
                ['identifier' => 'title', 'type' => 'string'],
                ['identifier' => 'subtitle', 'type' => 'string'],
            ],
        ]);
        $registry = new ExtensionRuntimeContributionRegistry();

        $registry->add($extension, [$table, $schema]);

        self::assertSame([$table], $registry->extensionDatabaseTables());
        self::assertSame([$schema], $registry->extensionContentSchemas());
    }

    public function testItAcceptsPublicEventListenerContributionsWithStablePriorityOrdering(): void
    {
        $registry = new ExtensionRuntimeContributionRegistry();

        $registry->add($this->extension([ExtensionScope::Module]), [
            new ExtensionEventListenerContribution(ViewContextEvent::class, static function (): void {
            }, priority: 0),
            new ExtensionEventListenerContribution(ViewContextEvent::class, static function (): void {
            }, priority: 20),
            new ExtensionEventListenerContribution(ViewContextEvent::class, static function (): void {
            }, priority: 20),
        ]);

        $listeners = $registry->extensionEventListeners(ViewContextEvent::class);

        self::assertSame([20, 20, 0], array_map(static fn ($listener): int => $listener->priority(), $listeners));
        self::assertSame([1, 2, 0], array_map(static fn ($listener): int => $listener->sequence(), $listeners));
        self::assertSame('demo-module', $listeners[0]->extensionName());
    }

    public function testItRejectsUnregisteredPublicEventListenersWithoutPartialState(): void
    {
        $registry = new ExtensionRuntimeContributionRegistry();

        try {
            $registry->add($this->extension([ExtensionScope::Module]), [
                new ExtensionSettingDefinition(
                    'demo-module',
                    'display.mode',
                    'extension.demo_module.display_mode.label',
                    'compact',
                ),
                new ExtensionEventListenerContribution(ExtensionRuntimeContributionRegistryContractTestEvent::class, static function (): void {
                }),
            ]);

            self::fail('Expected unregistered public events to be rejected.');
        } catch (\Throwable $error) {
            self::assertStringContainsString('message.extension.runtime.contribution_unsupported', $error->getMessage());
        }

        self::assertSame([], $registry->extensionSettings());
        self::assertSame([], $registry->extensionEventListeners(ExtensionRuntimeContributionRegistryContractTestEvent::class));
    }

    public function testItAcceptsProviderContributionsForMatchingProviderScopes(): void
    {
        $registry = new ExtensionRuntimeContributionRegistry();
        $provider = static fn (): string => 'verified';

        $registry->add($this->extension([ExtensionScope::CaptchaProvider]), new ExtensionProviderContribution(ExtensionScope::CaptchaProvider, $provider));

        self::assertSame('demo-module', $registry->provider(ExtensionScope::CaptchaProvider)?->extensionName());
        self::assertSame('verified', ($registry->provider(ExtensionScope::CaptchaProvider)?->provider())());
    }

    public function testItRejectsProviderContributionsWithoutMatchingProviderScope(): void
    {
        $registry = new ExtensionRuntimeContributionRegistry();

        try {
            $registry->add($this->extension([ExtensionScope::Module]), [
                new ExtensionSettingDefinition(
                    'demo-module',
                    'display.mode',
                    'extension.demo_module.display_mode.label',
                    'compact',
                ),
                new ExtensionProviderContribution(ExtensionScope::CaptchaProvider, static fn (): string => 'verified'),
            ]);

            self::fail('Expected provider contributions to require the matching provider scope.');
        } catch (\Throwable $error) {
            self::assertStringContainsString('message.extension.runtime.contribution_unsupported', $error->getMessage());
        }

        self::assertSame([], $registry->extensionSettings());
        self::assertNull($registry->provider(ExtensionScope::CaptchaProvider));
    }

    public function testItRejectsProviderContributionsForNonProviderScopes(): void
    {
        $this->expectExceptionMessage('message.extension.runtime.contribution_unsupported');

        (new ExtensionRuntimeContributionRegistry())->add(
            $this->extension([ExtensionScope::Module]),
            new ExtensionProviderContribution(ExtensionScope::Module, static fn (): string => 'verified'),
        );
    }

    public function testItRejectsDuplicateProviderContributionsWithoutReplacingExistingProvider(): void
    {
        $registry = new ExtensionRuntimeContributionRegistry();
        $registry->add($this->extension([ExtensionScope::CaptchaProvider]), new ExtensionProviderContribution(ExtensionScope::CaptchaProvider, static fn (): string => 'first'));

        try {
            $registry->add($this->extension([ExtensionScope::CaptchaProvider]), [
                new ExtensionSettingDefinition(
                    'demo-module',
                    'display.mode',
                    'extension.demo_module.display_mode.label',
                    'compact',
                ),
                new ExtensionProviderContribution(ExtensionScope::CaptchaProvider, static fn (): string => 'second'),
            ]);

            self::fail('Expected duplicate provider contributions to be rejected.');
        } catch (\Throwable $error) {
            self::assertStringContainsString('message.extension.runtime.contribution_unsupported', $error->getMessage());
        }

        self::assertSame([], $registry->extensionSettings());
        self::assertSame('first', ($registry->provider(ExtensionScope::CaptchaProvider)?->provider())());
    }

    public function testItRejectsDatabaseContributionsWithoutDatabaseScope(): void
    {
        $this->expectExceptionMessage('message.extension.database.contribution_invalid');

        (new ExtensionRuntimeContributionRegistry())->add(
            $this->extension([ExtensionScope::Module]),
            ExtensionDatabaseTable::create('entry', [ExtensionDatabaseColumn::string('uid', 36)], ['uid']),
        );
    }

    public function testItRejectsContentSchemaContributionsWithoutContentSchemaScope(): void
    {
        $this->expectExceptionMessage('message.extension.content_schema.contribution_invalid');

        (new ExtensionRuntimeContributionRegistry())->add(
            $this->extension([ExtensionScope::Module]),
            ExtensionContentSchemaDefinition::create('article', ['en' => 'Article'], [
                'fields' => [
                    ['identifier' => 'title', 'type' => 'string'],
                    ['identifier' => 'subtitle', 'type' => 'string'],
                ],
            ]),
        );
    }

    public function testItRejectsApiContributionsWithoutApiScope(): void
    {
        $this->expectExceptionMessage('message.api.endpoint.owner_invalid');

        (new ExtensionRuntimeContributionRegistry())->add(
            $this->extension([ExtensionScope::Module]),
            new ApiEndpointDefinition(
                'demo-module',
                'GET',
                '/api/v1/extensions/demo-module/demo',
                'api_v1_endpoint_dispatch',
                'getDemoModuleExtensionEndpoint',
                'Return extension demo data.',
                'extensions.demo-module.demo',
                ['extensions-demo-module-demo'],
            ),
        );
    }

    public function testItRejectsSettingDefinitionsForForeignOwners(): void
    {
        $this->expectExceptionMessage('message.extension.runtime.contribution_unsupported');

        (new ExtensionRuntimeContributionRegistry())->add(
            $this->extension([ExtensionScope::Module]),
            new ExtensionSettingDefinition('other-module', 'display.mode', 'Display mode', 'compact'),
        );
    }

    public function testItAcceptsViewContributionsInsideExtensionOwnedSurfaceNamespace(): void
    {
        $registry = new ExtensionRuntimeContributionRegistry();

        $registry->add($this->extension([ExtensionScope::Module]), [
            new StaticViewInjection(
                'ext-demo-module-public',
                ViewSurface::Public,
                'demo-module',
                'ext.demo_module.public.label',
                '@frontend/demo-module/public.html.twig',
            ),
            new StaticViewInjection(
                'ext-demo-module-admin',
                ViewSurface::Admin,
                'demo-module',
                'ext.demo_module.admin.label',
                '@backend/demo-module/admin.html.twig',
            ),
            new DynamicViewInjection(
                'ext-demo-module-dynamic',
                ViewSurface::Public,
                DynamicViewInjectionSlot::AfterContent,
                '@frontend/demo-module/after-content.html.twig',
            ),
        ]);

        self::assertCount(2, $registry->staticViewInjections());
        self::assertCount(1, $registry->dynamicViewInjections());
    }

    public function testItRejectsPublicViewContributionsOutsideFrontendNamespace(): void
    {
        $this->expectExceptionMessage('message.extension.runtime.contribution_unsupported');

        (new ExtensionRuntimeContributionRegistry())->add(
            $this->extension([ExtensionScope::Module]),
            new StaticViewInjection(
                'ext-demo-module-public',
                ViewSurface::Public,
                'demo-module',
                'ext.demo_module.public.label',
                '@backend/demo-module/admin.html.twig',
            ),
        );
    }

    public function testItRejectsDynamicViewContributionsOutsideSurfaceNamespace(): void
    {
        $this->expectExceptionMessage('message.extension.runtime.contribution_unsupported');

        (new ExtensionRuntimeContributionRegistry())->add(
            $this->extension([ExtensionScope::Module]),
            new DynamicViewInjection(
                'ext-demo-module-dynamic',
                ViewSurface::Public,
                DynamicViewInjectionSlot::AfterContent,
                '@backend/demo-module/admin.html.twig',
            ),
        );
    }

    public function testItRejectsConfigurableViewContributionsOutsideSurfaceNamespace(): void
    {
        $this->expectExceptionMessage('message.extension.runtime.contribution_unsupported');

        (new ExtensionRuntimeContributionRegistry())->add(
            $this->extension([ExtensionScope::Module]),
            new ConfigurableStaticViewInjectionSet(
                'demo-module',
                'demo.route',
                ViewSurface::Public,
                'demo',
                [
                    new ConfigurableStaticViewInjectionRoute(
                        'ext-demo-module-configurable',
                        '',
                        'ext.demo_module.public.label',
                        '@backend/demo-module/admin.html.twig',
                    ),
                ],
            ),
        );
    }

    public function testItRejectsConfigurableViewContributionsForForeignOwners(): void
    {
        $this->expectExceptionMessage('message.extension.runtime.contribution_unsupported');

        (new ExtensionRuntimeContributionRegistry())->add(
            $this->extension([ExtensionScope::Module]),
            new ConfigurableStaticViewInjectionSet(
                'other-module',
                'demo.route',
                ViewSurface::Public,
                'demo',
                [
                    new ConfigurableStaticViewInjectionRoute(
                        'ext-demo-module-configurable',
                        '',
                        'ext.demo_module.public.label',
                        '@frontend/demo-module/public.html.twig',
                    ),
                ],
            ),
        );
    }

    public function testItRejectsSchedulerTaskIdentifiersOutsideExtensionNamespace(): void
    {
        $this->expectExceptionMessage('message.extension.runtime.contribution_unsupported');

        (new ExtensionRuntimeContributionRegistry())->add(
            $this->extension([ExtensionScope::Module]),
            new SchedulerTaskDefinition(
                'other-module.cleanup',
                'extension.demo_module.cleanup.label',
                'extension.demo_module.cleanup.description',
                'demo-module',
                SchedulerTaskType::Callable,
                'demo-module.cleanup',
                '*/15 * * * *',
            ),
        );
    }

    public function testItAcceptsHyphenatedExtensionSchedulerIdentifiers(): void
    {
        $registry = new ExtensionRuntimeContributionRegistry();

        $registry->add(
            $this->extension([ExtensionScope::Module]),
            new SchedulerTaskDefinition(
                'demo-module.cleanup',
                'extension.demo_module.cleanup.label',
                'extension.demo_module.cleanup.description',
                'demo-module',
                SchedulerTaskType::Callable,
                'demo-module.cleanup',
                '*/15 * * * *',
            ),
        );

        self::assertSame('demo-module.cleanup', $registry->schedulerTasks()[0]->identifier());
    }

    public function testItRejectsSchedulerTaskTargetsOutsideExtensionNamespace(): void
    {
        $this->expectExceptionMessage('message.extension.runtime.contribution_unsupported');

        (new ExtensionRuntimeContributionRegistry())->add(
            $this->extension([ExtensionScope::Module]),
            new SchedulerTaskDefinition(
                'demo-module.cleanup',
                'extension.demo_module.cleanup.label',
                'extension.demo_module.cleanup.description',
                'demo-module',
                SchedulerTaskType::Callable,
                'other-module.cleanup',
                '*/15 * * * *',
            ),
        );
    }

    public function testItScopesSchedulerRuntimeProvidersToExtensionTargets(): void
    {
        $registry = new ExtensionRuntimeContributionRegistry();
        $registry->add($this->extension([ExtensionScope::Module]), new class implements SchedulerCallableProviderInterface, SchedulerActionQueueProviderInterface {
            public function schedulerCallable(string $target): ?callable
            {
                return static fn (): SchedulerTaskExecution => SchedulerTaskExecution::success(['target' => $target]);
            }

            public function schedulerActionQueue(string $target): ?ActionQueue
            {
                return ActionQueue::create($target);
            }
        });

        self::assertNotNull($registry->schedulerCallable('demo-module.cleanup'));
        self::assertNull($registry->schedulerCallable('other-module.cleanup'));
        self::assertSame('demo-module.queue', $registry->schedulerActionQueue('demo-module.queue')?->name());
        self::assertNull($registry->schedulerActionQueue('other-module.queue'));
    }

    public function testItRegistersExtensionOperationQueuesForLiveAndSchedulerUse(): void
    {
        $registry = new ExtensionRuntimeContributionRegistry();
        $registry->add($this->extension([ExtensionScope::Module]), ExtensionContributions::create()
            ->operation(new ExtensionOperationDefinition(
                'demo-module.cleanup',
                'ext.demo-module.cleanup.label',
                'ext.demo-module.cleanup.description',
            ))
            ->actionQueueProvider(new class implements ExtensionActionQueueProviderInterface {
                public function extensionActionQueue(string $target, array $payload = []): ?ActionQueue
                {
                    if ('demo-module.cleanup' !== $target) {
                        return null;
                    }

                    return ActionQueue::create('extension cleanup', context: [
                        'target' => $target,
                        'payload' => $payload,
                    ]);
                }
            }));

        self::assertSame('demo-module.cleanup', $registry->extensionOperations('demo-module')[0]->target());
        self::assertSame('extension cleanup', $registry->extensionActionQueue('demo-module.cleanup', ['mode' => 'fast'])?->name());
        self::assertSame('fast', $registry->extensionActionQueue('demo-module.cleanup', ['mode' => 'fast'])?->context()['payload']['mode']);
        self::assertSame('extension cleanup', $registry->schedulerActionQueue('demo-module.cleanup')?->name());
        self::assertNull($registry->extensionActionQueue('other-module.cleanup'));
    }

    public function testItRejectsForeignExtensionOperationDefinitions(): void
    {
        $this->expectExceptionMessage('message.extension.runtime.contribution_unsupported');

        (new ExtensionRuntimeContributionRegistry())->add(
            $this->extension([ExtensionScope::Module]),
            new ExtensionOperationDefinition(
                'demo-module.cleanup',
                'ext.other.cleanup.label',
                'ext.demo-module.cleanup.description',
            ),
        );
    }

    public function testItRejectsDuplicateExtensionOperationTargets(): void
    {
        $this->expectExceptionMessage('message.extension.runtime.contribution_unsupported');

        (new ExtensionRuntimeContributionRegistry())->add($this->extension([ExtensionScope::Module]), [
            new ExtensionOperationDefinition(
                'demo-module.cleanup',
                'ext.demo-module.cleanup.label',
                'ext.demo-module.cleanup.description',
            ),
            new ExtensionOperationDefinition(
                'demo-module.cleanup_alias',
                'ext.demo-module.cleanup_alias.label',
                'ext.demo-module.cleanup_alias.description',
                'demo-module.cleanup',
            ),
        ]);
    }

    /**
     * @param list<ExtensionScope> $scopes
     */
    private function extension(array $scopes): Extension
    {
        return new Extension(
            '10000000-0000-7000-8000-000000000701',
            $scopes,
            'demo-module',
            'extensions/demo-module',
            ExtensionStatus::Active,
        );
    }
}

final class ExtensionRuntimeContributionRegistryContractTestEvent extends Event implements PublicEventInterface
{
}
