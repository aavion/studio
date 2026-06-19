<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Config\Config;
use App\Content\ContentStatus;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Extension\ActiveExtensionProvider;
use App\Core\Extension\Content\ExtensionContentSchemaDefinition;
use App\Core\Extension\Content\ExtensionContentSchemaImpact;
use App\Core\Extension\Content\ExtensionContentSchemaSynchronizer;
use App\Core\Extension\Database\ExtensionDatabaseSchemaSynchronizer;
use App\Core\Extension\ExtensionActivationContributionApplier;
use App\Core\Extension\ExtensionStatus;
use App\Core\Extension\ExtensionActivator;
use App\Core\Extension\ExtensionContributionReader;
use App\Core\Extension\ExtensionDependencyResolver;
use App\Core\Extension\ExtensionLifecycleAssetRebuilderInterface;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Extension\ExtensionPhpLoader;
use App\Core\Extension\ExtensionRuntimeContributionRegistry;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ContentItem;
use App\Entity\ContentRevision;
use App\Entity\ContentSchema;
use App\Entity\Extension;
use App\Entity\SchedulerTask;
use App\Scheduler\SchedulerSettings;
use App\Scheduler\SchedulerTaskRegistry;
use App\Scheduler\SchedulerTaskStatus;
use App\Scheduler\SchedulerTaskSynchronizer;
use App\Scheduler\SchedulerTaskType;
use App\Tests\Support\FilesystemTestHelper;
use App\Tests\Support\NullWorkflowResultMessageReporter;
use App\View\SystemExtensionMetadataProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ExtensionActivatorTest extends KernelTestCase
{
    use FilesystemTestHelper;

    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private FakeExtensionLifecycleAssetRebuilder $assetRebuilder;
    private ?string $temporaryProjectDir = null;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->assetRebuilder = new FakeExtensionLifecycleAssetRebuilder();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->dropTableIfExists('demo_module_entry');

        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        if (null !== $this->temporaryProjectDir) {
            $this->removeDirectory($this->temporaryProjectDir);
            $this->temporaryProjectDir = null;
        }

        parent::tearDown();
    }

    public function testItActivatesInactiveExtensionsAndRunsAssetRebuild(): void
    {
        $this->insertExtension('demo-module', ['module'], 'inactive');

        $result = $this->activator()->activate('demo-module', 'test');

        self::assertTrue($result->isSuccess());
        self::assertSame('active', $this->extensionStatus('demo-module'));
        self::assertSame(['test'], $this->assetRebuilder->environments);
        self::assertSame([[
            'extension' => 'demo-module',
            'action' => 'activated',
            'status' => 'active',
        ]], $result->value()['changes']);
    }

    public function testItAppliesDatabaseContributionsDuringActivation(): void
    {
        $this->temporaryProjectDir = $this->createTemporaryDirectory('system-extension-database');
        $this->insertExtension('demo-module', ['module', 'database'], 'inactive');
        $this->writeTestFile($this->temporaryProjectDir, 'extensions/demo-module/extension.php', <<<'PHP'
<?php

use App\Core\Extension\Database\ExtensionDatabaseColumn;
use App\Core\Extension\Database\ExtensionDatabaseTable;

return [
    ExtensionDatabaseTable::create('entry', [
        ExtensionDatabaseColumn::string('uid', 36),
    ], ['uid']),
];
PHP);

        $result = $this->activatorWithContributionApplier()->activate('demo-module', 'test', rebuildAssets: false);

        self::assertTrue($result->isSuccess());
        self::assertContains('demo_module_entry', $this->connection->createSchemaManager()->listTableNames());
    }

    public function testItDoesNotApplyDatabaseContributionsWhenAssetRebuildFails(): void
    {
        $this->temporaryProjectDir = $this->createTemporaryDirectory('system-extension-database-rebuild-failure');
        $this->insertExtension('demo-module', ['module', 'database'], 'inactive');
        $this->writeTestFile($this->temporaryProjectDir, 'extensions/demo-module/extension.php', <<<'PHP'
<?php

use App\Core\Extension\Database\ExtensionDatabaseColumn;
use App\Core\Extension\Database\ExtensionDatabaseTable;

return [
    ExtensionDatabaseTable::create('entry', [
        ExtensionDatabaseColumn::string('uid', 36),
    ], ['uid']),
];
PHP);
        $this->assetRebuilder->result = WorkflowResult::failed([
            Message::create(
                ExtensionMessageCode::EXTENSION_ASSET_SYNC_FAILED,
                ExtensionMessageKey::EXTENSION_ASSET_SYNC_FAILED,
                ['%message%' => 'rebuild failed'],
                level: MessageLevel::Error,
            ),
        ]);

        $result = $this->activatorWithContributionApplier()->activate('demo-module', 'test');

        self::assertFalse($result->isSuccess());
        self::assertSame('inactive', $this->extensionStatus('demo-module'));
        self::assertNotContains('demo_module_entry', $this->connection->createSchemaManager()->listTableNames());
    }

    public function testItRollsBackActivationWhenDatabaseContributionIsNotAllowed(): void
    {
        $this->temporaryProjectDir = $this->createTemporaryDirectory('system-extension-database-scope-failure');
        $this->insertExtension('demo-module', ['module'], 'inactive');
        $this->writeTestFile($this->temporaryProjectDir, 'extensions/demo-module/extension.php', <<<'PHP'
<?php

use App\Core\Extension\Database\ExtensionDatabaseColumn;
use App\Core\Extension\Database\ExtensionDatabaseTable;

return [
    ExtensionDatabaseTable::create('entry', [
        ExtensionDatabaseColumn::string('uid', 36),
    ], ['uid']),
];
PHP);

        $result = $this->activatorWithContributionApplier()->activate('demo-module', 'test');

        self::assertFalse($result->isSuccess());
        self::assertSame('inactive', $this->extensionStatus('demo-module'));
        self::assertSame(['test', 'test'], $this->assetRebuilder->environments);
        self::assertSame('extension.database.contribution_invalid', $result->firstIssue()?->code());
    }

    public function testActivatedExtensionSchedulerTaskCanBeRegisteredAndEnabled(): void
    {
        $this->temporaryProjectDir = $this->createTemporaryDirectory('system-extension-scheduler');
        $this->insertExtension('demo-module', ['module'], 'inactive');
        $this->writeTestFile($this->temporaryProjectDir, 'extensions/demo-module/extension.php', <<<'PHP'
<?php

use App\Scheduler\SchedulerTaskDefinition;

return [
    SchedulerTaskDefinition::command(
        'demo-module.cleanup',
        'ext.demo_module.scheduler.cleanup.label',
        'ext.demo_module.scheduler.cleanup.description',
        'demo:cleanup',
        '*/20 * * * *',
        'demo-module',
        false,
    ),
];
PHP);

        self::assertTrue($this->activator()->activate('demo-module', 'test', rebuildAssets: false)->isSuccess());

        $runtimeContributions = new ExtensionRuntimeContributionRegistry();
        $loadResult = (new ExtensionPhpLoader(
            new ActiveExtensionProvider($this->entityManager),
            $this->entityManager,
            $this->temporaryProjectDir,
            new NullWorkflowResultMessageReporter(),
            runtimeContributions: $runtimeContributions,
        ))->loadActiveExtensions();
        self::assertTrue($loadResult->isSuccess());

        $tasks = (new SchedulerTaskSynchronizer(
            new SchedulerTaskRegistry([$runtimeContributions]),
            $this->entityManager,
            new SchedulerSettings(new Config($this->connection)),
        ))->synchronize();

        self::assertCount(1, $tasks);
        self::assertSame('demo-module.cleanup', $tasks[0]->identifier());
        self::assertSame('demo-module', $tasks[0]->source());
        self::assertSame(SchedulerTaskType::Command, $tasks[0]->type());
        self::assertSame('*/20 * * * *', $tasks[0]->cronExpression());
        self::assertFalse($tasks[0]->trusted());

        $task = $this->entityManager->find(SchedulerTask::class, 'demo-module.cleanup');
        self::assertInstanceOf(SchedulerTask::class, $task);
        $task->activate('*/10 * * * *');
        $this->entityManager->flush();

        $this->entityManager->clear();
        $enabledTask = $this->entityManager->find(SchedulerTask::class, 'demo-module.cleanup');
        self::assertInstanceOf(SchedulerTask::class, $enabledTask);
        self::assertSame(SchedulerTaskStatus::Active, $enabledTask->status());
        self::assertSame('*/10 * * * *', $enabledTask->cronExpression());
    }

    public function testItDeactivatesConflictingSingleActiveScopes(): void
    {
        $this->insertExtension('old-theme', ['frontend-theme'], 'active');
        $this->insertExtension('new-theme', ['frontend-theme'], 'inactive');
        $this->insertExtension('utility-module', ['module'], 'active');

        $result = $this->activator()->activate('new-theme', 'test', rebuildAssets: false);

        self::assertTrue($result->isSuccess());
        self::assertSame('inactive', $this->extensionStatus('old-theme'));
        self::assertSame('active', $this->extensionStatus('new-theme'));
        self::assertSame('active', $this->extensionStatus('utility-module'));
        self::assertSame(['old-theme', 'new-theme'], array_column($result->value()['changes'], 'extension'));
        self::assertSame([], $this->assetRebuilder->environments);
    }

    public function testItArchivesPublishedContentUsingExtensionSchemasDuringDeactivation(): void
    {
        $this->insertExtension('demo-module', ['module', 'content-schema'], 'active');
        $extension = $this->entityManager->getRepository(Extension::class)->findOneBy(['extensionName' => 'demo-module']);
        self::assertInstanceOf(Extension::class, $extension);
        $schema = $this->extensionSchema($extension);
        $content = $this->contentUsingSchema('c2000000-0000-7000-8000-000000000001', 'extension-content', $schema);
        $content->publish();
        $this->entityManager->persist($content);
        $this->entityManager->flush();

        $activator = $this->activatorWithContentImpact();
        $plan = $activator->planDeactivation('demo-module');

        self::assertTrue($plan->isSuccess());
        self::assertSame(1, $plan->value()['content_impact']['public_count']);
        self::assertSame(['/extension-content'], array_column($plan->value()['content_impact']['items'], 'path'));

        $result = $activator->deactivate('demo-module', 'test', rebuildAssets: false);

        self::assertTrue($result->isSuccess());
        self::assertSame(ContentStatus::Archived, $content->status());
    }

    public function testItDeactivatesDependentsOfConflictingSingleActiveScopes(): void
    {
        $this->insertExtension('old-theme', ['frontend-theme'], 'active');
        $this->insertExtension('captcha-provider', ['captcha-provider'], 'active', "[['old-theme', '1.0.0']]");
        $this->insertExtension('new-theme', ['frontend-theme'], 'inactive');

        $result = $this->activator()->activate('new-theme', 'test', rebuildAssets: false);

        self::assertTrue($result->isSuccess());
        self::assertSame('inactive', $this->extensionStatus('old-theme'));
        self::assertSame('inactive', $this->extensionStatus('captcha-provider'));
        self::assertSame('active', $this->extensionStatus('new-theme'));
        self::assertSame(['captcha-provider', 'old-theme', 'new-theme'], array_column($result->value()['changes'], 'extension'));
    }

    public function testItRunsOneAssetRebuildForBatchedActivationChanges(): void
    {
        $this->insertExtension('old-theme', ['frontend-theme'], 'active');
        $this->insertExtension('theme-tools', ['module'], 'inactive');
        $this->insertExtension('new-theme', ['frontend-theme'], 'inactive', "[['theme-tools', '1.0.0']]");

        $result = $this->activator()->activate('new-theme', 'test');

        self::assertTrue($result->isSuccess());
        self::assertSame('inactive', $this->extensionStatus('old-theme'));
        self::assertSame('active', $this->extensionStatus('new-theme'));
        self::assertSame('active', $this->extensionStatus('theme-tools'));
        self::assertSame(['test'], $this->assetRebuilder->environments);
        self::assertSame(['old-theme', 'new-theme', 'theme-tools'], array_column($result->value()['changes'], 'extension'));
    }

    public function testItPlansDependenciesAndConflictsBeforeActivation(): void
    {
        $this->insertExtension('old-theme', ['frontend-theme'], 'active');
        $this->insertExtension('theme-tools', ['module'], 'inactive');
        $this->insertExtension('new-theme', ['frontend-theme'], 'inactive', "[['theme-tools', '1.0.0']]");

        $result = $this->activator()->planActivation('new-theme');

        self::assertTrue($result->isSuccess());
        self::assertSame(['new-theme', 'theme-tools'], $result->value()['activate']);
        self::assertSame(['old-theme'], $result->value()['deactivate']);
        self::assertSame([[
            'extension' => 'theme-tools',
            'required_min_version' => '1.0.0',
            'installed_version' => '1.0.0',
            'status' => 'inactive',
            'required_by' => 'new-theme',
        ]], $result->value()['dependencies']);
        self::assertSame('extension.dependency.resolved', $result->messages()[0]->code());
        self::assertSame(MessageLevel::Debug, $result->messages()[0]->level());
    }

    public function testItActivatesInactiveDependenciesWithTheTargetExtension(): void
    {
        $this->insertExtension('theme-tools', ['module'], 'inactive');
        $this->insertExtension('new-theme', ['frontend-theme'], 'inactive', "[['theme-tools', '1.0.0']]");

        $result = $this->activator()->activate('new-theme', 'test', rebuildAssets: false);

        self::assertTrue($result->isSuccess());
        self::assertSame('active', $this->extensionStatus('new-theme'));
        self::assertSame('active', $this->extensionStatus('theme-tools'));
        self::assertSame(['new-theme', 'theme-tools'], array_column($result->value()['changes'], 'extension'));
    }

    public function testItTreatsSystemAsSatisfiedVirtualDependency(): void
    {
        $systemVersion = (new SystemExtensionMetadataProvider(dirname(__DIR__, 3)))->metadata()['version'];
        self::assertIsString($systemVersion);

        $this->insertExtension('demo-module', ['module'], 'inactive', sprintf('[["system", "%s"]]', $systemVersion));

        $result = $this->activatorWithSystemDependencySupport()->planActivation('demo-module');

        self::assertTrue($result->isSuccess());
        self::assertSame(['demo-module'], $result->value()['activate']);
        self::assertSame([[
            'extension' => 'system',
            'required_min_version' => $systemVersion,
            'installed_version' => $systemVersion,
            'status' => 'active',
            'required_by' => 'demo-module',
        ]], $result->value()['dependencies']);
    }

    public function testItBlocksMissingExtensionDependencies(): void
    {
        $this->insertExtension('new-theme', ['frontend-theme'], 'inactive', "[['missing-tools', '1.0.0']]");

        $result = $this->activator()->planActivation('new-theme');

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.dependency.missing', $result->firstIssue()?->code());
        self::assertSame('inactive', $this->extensionStatus('new-theme'));
    }

    public function testItBlocksMalformedExtensionDependencies(): void
    {
        $this->insertExtension('new-theme', ['frontend-theme'], 'inactive', '["theme-tools >=1.0"]');

        $result = $this->activator()->planActivation('new-theme');

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.dependency.invalid', $result->firstIssue()?->code());
        self::assertSame('inactive', $this->extensionStatus('new-theme'));
    }

    public function testItBlocksUnsatisfiedExtensionDependencyVersions(): void
    {
        $this->insertExtension('theme-tools', ['module'], 'active', version: '1.0.0');
        $this->insertExtension('new-theme', ['frontend-theme'], 'inactive', "[['theme-tools', '1.1.0']]");

        $result = $this->activator()->planActivation('new-theme');

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.dependency.version_unsatisfied', $result->firstIssue()?->code());
        self::assertSame('inactive', $this->extensionStatus('new-theme'));
    }

    public function testItBlocksCircularExtensionDependencies(): void
    {
        $this->insertExtension('demo-module', ['module'], 'inactive', "[['demo-tools', '1.0.0']]");
        $this->insertExtension('demo-tools', ['module'], 'inactive', "[['demo-module', '1.0.0']]");

        $result = $this->activator()->planActivation('demo-module');

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.dependency.cycle', $result->firstIssue()?->code());
        self::assertSame(['demo-module', 'demo-tools', 'demo-module'], $result->firstIssue()?->context()['cycle']);
        self::assertSame('inactive', $this->extensionStatus('demo-module'));
        self::assertSame('inactive', $this->extensionStatus('demo-tools'));
    }

    public function testItBlocksExtensionSelfDependencies(): void
    {
        $this->insertExtension('demo-module', ['module'], 'inactive', "[['demo-module', '1.0.0']]");

        $result = $this->activator()->planActivation('demo-module');

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.dependency.cycle', $result->firstIssue()?->code());
        self::assertSame(['demo-module', 'demo-module'], $result->firstIssue()?->context()['cycle']);
        self::assertSame('inactive', $this->extensionStatus('demo-module'));
    }

    public function testItDeactivatesActiveExtensions(): void
    {
        $this->insertExtension('demo-module', ['module'], 'active');

        $result = $this->activator()->deactivate('demo-module', 'test', rebuildAssets: false);

        self::assertTrue($result->isSuccess());
        self::assertSame('inactive', $this->extensionStatus('demo-module'));
        self::assertSame([[
            'extension' => 'demo-module',
            'action' => 'deactivated',
            'status' => 'inactive',
        ]], $result->value()['changes']);
    }

    public function testItPlansDependentCascadeBeforeDeactivation(): void
    {
        $this->insertExtension('demo-theme', ['frontend-theme'], 'active');
        $this->insertExtension('captcha-provider', ['captcha-provider'], 'active', "[['demo-theme', '1.0.0']]");

        $result = $this->activator()->planDeactivation('demo-theme');

        self::assertTrue($result->isSuccess());
        self::assertSame(['captcha-provider', 'demo-theme'], $result->value()['deactivate']);
        self::assertSame([
            [
                'extension' => 'captcha-provider',
                'action' => 'deactivated',
                'status' => 'inactive',
            ],
            [
                'extension' => 'demo-theme',
                'action' => 'deactivated',
                'status' => 'inactive',
            ],
        ], $result->value()['changes']);
    }

    public function testItDeactivatesActiveDependentsWithTheTargetExtension(): void
    {
        $this->insertExtension('demo-theme', ['frontend-theme'], 'active');
        $this->insertExtension('captcha-provider', ['captcha-provider'], 'active', "[['demo-theme', '1.0.0']]");

        $result = $this->activator()->deactivate('demo-theme', 'test', rebuildAssets: false);

        self::assertTrue($result->isSuccess());
        self::assertSame('inactive', $this->extensionStatus('demo-theme'));
        self::assertSame('inactive', $this->extensionStatus('captcha-provider'));
        self::assertSame(['captcha-provider', 'demo-theme'], array_column($result->value()['changes'], 'extension'));
    }

    public function testItBlocksFaultyOrRemovedExtensions(): void
    {
        foreach (['faulty', 'removed'] as $status) {
            $this->insertExtension('demo-'.$status, ['module'], $status);

            $result = $this->activator()->activate('demo-'.$status, 'test', rebuildAssets: false);

            self::assertFalse($result->isSuccess());
            self::assertSame('extension.lifecycle.status_blocked', $result->firstIssue()?->code());
            self::assertSame($status, $this->extensionStatus('demo-'.$status));
        }
    }

    public function testItRollsBackWhenAssetRebuildFails(): void
    {
        $this->insertExtension('demo-module', ['module'], 'inactive');
        $this->assetRebuilder->result = WorkflowResult::failed([
            Message::create(
                ExtensionMessageCode::EXTENSION_ASSET_SYNC_FAILED,
                ExtensionMessageKey::EXTENSION_ASSET_SYNC_FAILED,
                ['%message%' => 'rebuild failed'],
                level: MessageLevel::Error,
            ),
        ]);

        $result = $this->activator()->activate('demo-module', 'test');

        self::assertFalse($result->isSuccess());
        self::assertSame('inactive', $this->extensionStatus('demo-module'));
        self::assertTrue($result->context()['rolled_back']);
    }

    public function testItReportsMissingExtensions(): void
    {
        $result = $this->activator()->activate('missing', 'test', rebuildAssets: false);

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.lifecycle.not_found', $result->firstIssue()?->code());
    }

    private function activator(): ExtensionActivator
    {
        return new ExtensionActivator($this->entityManager, $this->assetRebuilder, new NullWorkflowResultMessageReporter());
    }

    private function activatorWithSystemDependencySupport(): ExtensionActivator
    {
        return new ExtensionActivator(
            $this->entityManager,
            $this->assetRebuilder,
            new NullWorkflowResultMessageReporter(),
            new ExtensionDependencyResolver($this->entityManager, new SystemExtensionMetadataProvider(dirname(__DIR__, 3))),
        );
    }

    private function activatorWithContributionApplier(): ExtensionActivator
    {
        self::assertIsString($this->temporaryProjectDir);

        return new ExtensionActivator(
            $this->entityManager,
            $this->assetRebuilder,
            new NullWorkflowResultMessageReporter(),
            activationContributionApplier: new ExtensionActivationContributionApplier(
                new ExtensionContributionReader($this->temporaryProjectDir),
                new ExtensionDatabaseSchemaSynchronizer($this->connection),
                new ExtensionContentSchemaSynchronizer($this->entityManager),
                $this->connection,
            ),
        );
    }

    private function activatorWithContentImpact(): ExtensionActivator
    {
        return new ExtensionActivator(
            $this->entityManager,
            $this->assetRebuilder,
            new NullWorkflowResultMessageReporter(),
            contentSchemaImpact: new ExtensionContentSchemaImpact($this->entityManager),
        );
    }

    private function extensionSchema(Extension $extension): ContentSchema
    {
        $definition = ExtensionContentSchemaDefinition::create('article', ['en' => 'Article'], [
            'fields' => [
                ['identifier' => 'title', 'type' => 'string'],
                ['identifier' => 'subtitle', 'type' => 'string'],
            ],
        ]);
        $result = (new ExtensionContentSchemaSynchronizer($this->entityManager))->apply($extension, [$definition]);
        self::assertTrue($result->isSuccess());
        $schema = $this->entityManager->getRepository(ContentSchema::class)->findOneBy(['identifier' => 'demo_module_article']);
        self::assertInstanceOf(ContentSchema::class, $schema);
        self::assertNotNull($schema->activeVersion());

        return $schema;
    }

    private function contentUsingSchema(string $uid, string $slug, ContentSchema $schema): ContentItem
    {
        $content = new ContentItem($uid, $slug);
        self::assertNotNull($schema->activeVersion());
        $content->activateRevision(new ContentRevision(substr_replace($uid, '4', 0, 1), $content, 1, $schema->activeVersion()));

        return $content;
    }

    /**
     * @param list<string> $scopes
     */
    private function insertExtension(
        string $extensionName,
        array $scopes,
        string $status,
        string $dependencies = '[]',
        string $version = '1.0.0',
    ): void {
        $this->connection->insert('extension', [
            'uid' => $this->uuid(),
            'extension_scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
            'extension_name' => $extensionName,
            'path' => 'extensions/'.$extensionName,
            'manifest_version' => $version,
            'installed_version' => $version,
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

    private function extensionStatus(string $extensionName): string
    {
        $status = $this->connection->fetchOne(
            'SELECT status FROM extension WHERE extension_name = :extension_name',
            ['extension_name' => $extensionName],
        );

        self::assertIsString($status);

        return $status;
    }

    private function uuid(): string
    {
        return Uuid::v7()->toRfc4122();
    }

    private function dropTableIfExists(string $tableName): void
    {
        if (!in_array($tableName, $this->connection->createSchemaManager()->listTableNames(), true)) {
            return;
        }

        foreach ((array) $this->connection->getDatabasePlatform()->getDropTableSQL($tableName) as $sql) {
            $this->connection->executeStatement($sql);
        }
    }
}

final class FakeExtensionLifecycleAssetRebuilder implements ExtensionLifecycleAssetRebuilderInterface
{
    /**
     * @var list<string>
     */
    public array $environments = [];

    /**
     * @var WorkflowResult<mixed>
     */
    public WorkflowResult $result;

    public function __construct()
    {
        $this->result = WorkflowResult::success(context: ['fake' => true]);
    }

    public function rebuild(string $environment): WorkflowResult
    {
        $this->environments[] = $environment;

        return $this->result;
    }
}
