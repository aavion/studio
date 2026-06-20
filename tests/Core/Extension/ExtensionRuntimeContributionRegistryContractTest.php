<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Core\Extension\Content\ExtensionContentSchemaDefinition;
use App\Core\Extension\Database\ExtensionDatabaseColumn;
use App\Core\Extension\Database\ExtensionDatabaseTable;
use App\Core\Extension\ExtensionRuntimeContributionRegistry;
use App\Core\Extension\ExtensionScope;
use App\Core\Extension\ExtensionStatus;
use App\Core\Extension\Settings\ExtensionSettingDefinition;
use App\Core\Operation\ActionQueue;
use App\Entity\Extension;
use App\Scheduler\SchedulerActionQueueProviderInterface;
use App\Scheduler\SchedulerCallableProviderInterface;
use App\Scheduler\SchedulerTaskDefinition;
use App\Scheduler\SchedulerTaskExecution;
use App\Scheduler\SchedulerTaskType;
use PHPUnit\Framework\TestCase;

final class ExtensionRuntimeContributionRegistryContractTest extends TestCase
{
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
                'extension',
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
