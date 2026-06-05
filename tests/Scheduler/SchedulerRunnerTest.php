<?php

declare(strict_types=1);

namespace App\Tests\Scheduler;

use App\Core\Config\Config;
use App\Core\Id\UuidFactory;
use App\Core\Log\MessageLoggerInterface;
use App\Core\Message\Message;
use App\Core\Package\ActivePackageProviderInterface;
use App\Core\Package\ExtensionPackageStatus;
use App\Core\Package\PackageScope;
use App\Entity\ExtensionPackage;
use App\Entity\SchedulerTask;
use App\Entity\SchedulerTaskRun;
use App\Scheduler\SchedulerLockFactory;
use App\Scheduler\SchedulerRunner;
use App\Scheduler\SchedulerSettings;
use App\Scheduler\SchedulerTaskDefinition;
use App\Scheduler\SchedulerTaskExecution;
use App\Scheduler\SchedulerTaskExecutorInterface;
use App\Scheduler\SchedulerTaskProviderInterface;
use App\Scheduler\SchedulerTaskRegistry;
use App\Scheduler\SchedulerTaskRunStatus;
use App\Scheduler\SchedulerTaskStatus;
use App\Scheduler\SchedulerTaskSynchronizer;
use App\Scheduler\SchedulerTaskType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Uid\Uuid;

final class SchedulerRunnerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    public function testItSynchronizesRegisteredTasksInactiveByDefault(): void
    {
        $tasks = $this->synchronizer()->synchronize();

        self::assertCount(1, $tasks);
        self::assertSame('system.test_task', $tasks[0]->identifier());
        self::assertSame(SchedulerTaskStatus::Inactive, $tasks[0]->status());
        self::assertNotNull($tasks[0]->nextDueAt());
    }

    public function testItHidesUntrustedPackageActionQueuesWhenDisabled(): void
    {
        $tasks = $this->synchronizer(new TestPackageActionQueueSchedulerTaskProvider())->synchronize();

        self::assertSame([], $tasks);
    }

    public function testItReportsForcedPackageActionQueueTaskAsSkippedWhenPolicyBlocksIt(): void
    {
        $task = new SchedulerTask(new SchedulerTaskDefinition(
            'demo.action_queue',
            'admin.scheduler.tasks.demo.label',
            'admin.scheduler.tasks.demo.description',
            'demo-package',
            SchedulerTaskType::ActionQueue,
            'demo.queue',
            '* * * * *',
            false,
        ));
        $task->activate('* * * * *');
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        $payload = $this->runner(
            new TestSchedulerTaskExecutor(true),
            new TestPackageActionQueueSchedulerTaskProvider(),
            new MutableActivePackageProvider(['demo-package']),
        )->run('demo.action_queue', true)->toArray();

        self::assertSame('completed', $payload['status']);
        self::assertSame('demo.action_queue', $payload['tasks'][0]['identifier']);
        self::assertSame('skipped', $payload['tasks'][0]['status']);
        self::assertSame('active', $payload['tasks'][0]['task_status']);
        self::assertSame('not_runnable', $payload['tasks'][0]['reason']);
    }

    public function testSystemSchedulerTaskDefinitionsWinIdentifierCollisions(): void
    {
        $registry = new SchedulerTaskRegistry([
            new TestCollidingPackageSchedulerTaskProvider(),
            new TestSchedulerTaskProvider(),
        ]);

        self::assertSame('system', $registry->definition('system.test_task')?->source());
    }

    public function testItDoesNotShowTasksWhosePackageNoLongerRegistersThem(): void
    {
        $staleTask = new SchedulerTask(new SchedulerTaskDefinition(
            'demo.stale_task',
            'admin.scheduler.tasks.demo.label',
            'admin.scheduler.tasks.demo.description',
            'demo-package',
            SchedulerTaskType::Command,
            'studio:test',
            '* * * * *',
            false,
        ));
        $staleTask->activate('* * * * *');
        $this->entityManager->persist($staleTask);
        $this->entityManager->flush();

        $tasks = $this->synchronizer()->synchronize();

        self::assertSame(['system.test_task'], array_map(static fn (SchedulerTask $task): string => $task->identifier(), $tasks));
    }

    public function testItDoesNotRunActiveDueTasksFromInactivePackages(): void
    {
        $this->synchronizer(new TestPackageCommandSchedulerTaskProvider())->synchronize();
        $task = $this->entityManager->find(SchedulerTask::class, 'demo.command');
        self::assertInstanceOf(SchedulerTask::class, $task);
        $task->activate('* * * * *');
        $this->entityManager->flush();

        $payload = $this->runner(new TestSchedulerTaskExecutor(true), new TestPackageCommandSchedulerTaskProvider())->run()->toArray();

        self::assertSame('completed', $payload['status']);
        self::assertSame([], $payload['tasks']);
        self::assertSame([], $this->entityManager->getRepository(SchedulerTaskRun::class)->findBy(['task' => $task]));
    }

    public function testItRunsForcedActiveTaskAndStoresRunHistory(): void
    {
        $this->synchronizer()->synchronize();
        $task = $this->entityManager->find(SchedulerTask::class, 'system.test_task');
        self::assertInstanceOf(SchedulerTask::class, $task);
        $task->activate('* * * * *');
        $this->entityManager->flush();

        $result = $this->runner(new TestSchedulerTaskExecutor(true))->run('system.test_task', true);

        self::assertSame('completed', $result->toArray()['status']);
        $updated = $this->entityManager->find(SchedulerTask::class, 'system.test_task');
        self::assertInstanceOf(SchedulerTask::class, $updated);
        self::assertSame(0, $updated->failureCount());
        self::assertNotNull($updated->lastSuccessAt());

        $runs = $this->entityManager->getRepository(SchedulerTaskRun::class)->findBy(['task' => $updated]);
        self::assertCount(1, $runs);
        self::assertSame(SchedulerTaskRunStatus::Success, $runs[0]->status());
    }

    public function testItReplacesInvalidRunContextAfterFlushFailure(): void
    {
        $this->synchronizer()->synchronize();
        $task = $this->entityManager->find(SchedulerTask::class, 'system.test_task');
        self::assertInstanceOf(SchedulerTask::class, $task);
        $task->activate('* * * * *');
        $this->entityManager->flush();

        $payload = $this->runner(new TestInvalidContextSchedulerTaskExecutor())->run('system.test_task', true)->toArray();
        $updated = $this->entityManager->find(SchedulerTask::class, 'system.test_task');
        self::assertInstanceOf(SchedulerTask::class, $updated);

        self::assertSame('completed', $payload['status']);
        self::assertSame('failed', $payload['tasks'][0]['status']);
        self::assertSame('active', $payload['tasks'][0]['task_status']);
        self::assertSame(1, $updated->failureCount());

        $runs = $this->entityManager->getRepository(SchedulerTaskRun::class)->findBy(['task' => $updated]);
        self::assertCount(1, $runs);
        self::assertSame(SchedulerTaskRunStatus::Failed, $runs[0]->status());
        self::assertArrayHasKey('exception', $runs[0]->context());
        self::assertArrayNotHasKey('resource', $runs[0]->context());
    }

    public function testItReportsForcedInactiveTaskAsSkipped(): void
    {
        $this->synchronizer()->synchronize();

        $payload = $this->runner(new TestSchedulerTaskExecutor(true))->run('system.test_task', true)->toArray();

        self::assertSame('completed', $payload['status']);
        self::assertSame('system.test_task', $payload['tasks'][0]['identifier']);
        self::assertSame('skipped', $payload['tasks'][0]['status']);
        self::assertSame('inactive', $payload['tasks'][0]['task_status']);
        self::assertSame('not_runnable', $payload['tasks'][0]['reason']);
    }

    public function testItRechecksTaskEligibilityBeforeExecutingStaleDueTasks(): void
    {
        $activePackages = new MutableActivePackageProvider(['demo-package']);
        $this->synchronizer(new TestMixedSchedulerTaskProvider())->synchronize();

        foreach (['system.first_task' => '-2 minutes', 'demo.command' => '-1 minute'] as $identifier => $dueAt) {
            $task = $this->entityManager->find(SchedulerTask::class, $identifier);
            self::assertInstanceOf(SchedulerTask::class, $task);
            $task->activate('* * * * *');
            $task->seedNextDue(new \DateTimeImmutable($dueAt));
        }
        $this->entityManager->flush();

        $payload = $this->runner(
            new TestPackageDeactivatingSchedulerTaskExecutor($activePackages),
            new TestMixedSchedulerTaskProvider(),
            $activePackages,
        )->run()->toArray();

        self::assertSame('completed', $payload['status']);
        self::assertSame('system.first_task', $payload['tasks'][0]['identifier']);
        self::assertSame('success', $payload['tasks'][0]['status']);
        self::assertSame('demo.command', $payload['tasks'][1]['identifier']);
        self::assertSame('skipped', $payload['tasks'][1]['status']);
        self::assertSame('not_runnable', $payload['tasks'][1]['reason']);
        self::assertSame([], $this->entityManager->getRepository(SchedulerTaskRun::class)->findBy([
            'task' => $this->entityManager->find(SchedulerTask::class, 'demo.command'),
        ]));
    }

    public function testItTimestampsEachDueTaskWhenItStarts(): void
    {
        $this->synchronizer(new TestMultipleSchedulerTaskProvider())->synchronize();

        foreach (['system.first_task' => '-2 minutes', 'system.second_task' => '-1 minute'] as $identifier => $dueAt) {
            $task = $this->entityManager->find(SchedulerTask::class, $identifier);
            self::assertInstanceOf(SchedulerTask::class, $task);
            $task->activate('* * * * *');
            $task->seedNextDue(new \DateTimeImmutable($dueAt));
        }
        $this->entityManager->flush();

        $this->runner(new TestDelayedSchedulerTaskExecutor(), new TestMultipleSchedulerTaskProvider())->run();
        $runs = $this->entityManager->getRepository(SchedulerTaskRun::class)->findBy([], ['startedAt' => 'ASC']);

        self::assertCount(2, $runs);
        self::assertGreaterThan(
            (float) $runs[0]->startedAt()->format('U.u'),
            (float) $runs[1]->startedAt()->format('U.u'),
        );
        self::assertLessThan(100, $runs[1]->durationMs());
    }

    public function testItDisablesTaskAfterThreeFailedRuns(): void
    {
        $this->synchronizer()->synchronize();
        $task = $this->entityManager->find(SchedulerTask::class, 'system.test_task');
        self::assertInstanceOf(SchedulerTask::class, $task);
        $task->activate('* * * * *');
        $this->entityManager->flush();

        $runner = $this->runner(new TestSchedulerTaskExecutor(false));
        $runner->run('system.test_task', true);
        $runner->run('system.test_task', true);
        $runner->run('system.test_task', true);

        $updated = $this->entityManager->find(SchedulerTask::class, 'system.test_task');
        self::assertInstanceOf(SchedulerTask::class, $updated);
        self::assertSame(3, $updated->failureCount());
        self::assertSame(SchedulerTaskStatus::Faulty, $updated->status());
    }

    public function testItReportsActiveNotDueTasksAsSkipped(): void
    {
        $this->synchronizer()->synchronize();
        $task = $this->entityManager->find(SchedulerTask::class, 'system.test_task');
        self::assertInstanceOf(SchedulerTask::class, $task);
        $task->activate('0 0 1 1 *');
        $this->entityManager->flush();

        $payload = $this->runner(new TestSchedulerTaskExecutor(true))->run()->toArray();

        self::assertSame('completed', $payload['status']);
        self::assertSame('system.test_task', $payload['tasks'][0]['identifier']);
        self::assertSame('skipped', $payload['tasks'][0]['status']);
    }

    public function testInvalidCronExpressionFailsTaskWithoutCrashingRunner(): void
    {
        $this->synchronizer()->synchronize();
        $task = $this->entityManager->find(SchedulerTask::class, 'system.test_task');
        self::assertInstanceOf(SchedulerTask::class, $task);
        $task->activate('not a cron');
        $this->entityManager->flush();

        $payload = $this->runner(new TestSchedulerTaskExecutor(true))->run('system.test_task', true)->toArray();

        self::assertSame('completed', $payload['status']);
        self::assertSame('failed', $payload['tasks'][0]['status']);
        self::assertSame('faulty', $payload['tasks'][0]['task_status']);
        self::assertSame(1, $payload['tasks'][0]['failure_count']);
    }

    public function testActivatingTaskResetsFailureStateAndDueTime(): void
    {
        $task = new SchedulerTask(SchedulerTaskDefinition::command(
            'system.failed_task',
            'admin.scheduler.tasks.failed.label',
            'admin.scheduler.tasks.failed.description',
            'studio:test',
            '* * * * *',
        ));
        $now = new \DateTimeImmutable();
        $task->markFailure($now, 1);

        $task->activate('0 * * * *');

        self::assertSame(SchedulerTaskStatus::Active, $task->status());
        self::assertSame(0, $task->failureCount());
        self::assertNull($task->nextDueAt());
        self::assertSame('0 * * * *', $task->cronExpression());
    }

    public function testDefinitionSyncKeepsUnmodifiedCronOnDefaultChanges(): void
    {
        $task = new SchedulerTask(SchedulerTaskDefinition::command(
            'system.sync_task',
            'admin.scheduler.tasks.sync.label',
            'admin.scheduler.tasks.sync.description',
            'studio:test',
            '* * * * *',
        ));
        $task->seedNextDue(new \DateTimeImmutable('+1 hour'));

        $task->syncDefinition(SchedulerTaskDefinition::command(
            'system.sync_task',
            'admin.scheduler.tasks.sync.label',
            'admin.scheduler.tasks.sync.description',
            'studio:test',
            '*/5 * * * *',
        ), new \DateTimeImmutable());

        self::assertSame('*/5 * * * *', $task->cronExpression());
        self::assertNull($task->nextDueAt());
    }

    public function testDefinitionSyncDeactivatesNewUntrustedActionQueues(): void
    {
        $task = new SchedulerTask(SchedulerTaskDefinition::command(
            'demo.sync_task',
            'admin.scheduler.tasks.sync.label',
            'admin.scheduler.tasks.sync.description',
            'studio:test',
            '* * * * *',
            'demo-package',
            false,
        ));
        $task->activate('* * * * *');

        $task->syncDefinition(new SchedulerTaskDefinition(
            'demo.sync_task',
            'admin.scheduler.tasks.sync.label',
            'admin.scheduler.tasks.sync.description',
            'demo-package',
            SchedulerTaskType::ActionQueue,
            'demo.queue',
            '* * * * *',
            false,
        ), new \DateTimeImmutable());

        self::assertSame(SchedulerTaskStatus::Inactive, $task->status());
        self::assertSame(SchedulerTaskType::ActionQueue, $task->type());
        self::assertSame(0, $task->failureCount());
        self::assertNull($task->nextDueAt());
    }

    public function testDefinitionSyncDeactivatesChangedUntrustedActionQueues(): void
    {
        $task = new SchedulerTask(new SchedulerTaskDefinition(
            'demo.sync_task',
            'admin.scheduler.tasks.sync.label',
            'admin.scheduler.tasks.sync.description',
            'demo-package',
            SchedulerTaskType::ActionQueue,
            'demo.old_queue',
            '* * * * *',
            false,
        ));
        $task->activate('* * * * *');

        $task->syncDefinition(new SchedulerTaskDefinition(
            'demo.sync_task',
            'admin.scheduler.tasks.sync.label',
            'admin.scheduler.tasks.sync.description',
            'demo-package',
            SchedulerTaskType::ActionQueue,
            'demo.new_queue',
            '* * * * *',
            false,
        ), new \DateTimeImmutable());

        self::assertSame(SchedulerTaskStatus::Inactive, $task->status());
        self::assertSame('demo.new_queue', $task->target());
        self::assertSame(0, $task->failureCount());
        self::assertNull($task->nextDueAt());
    }

    public function testTaskDefinitionsRejectInvalidTranslationKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SchedulerTaskDefinition::command(
            'system.bad_task',
            '<script>alert(1)</script>',
            'admin.scheduler.tasks.bad.description',
            'studio:test',
            '* * * * *',
        );
    }

    public function testTaskDefinitionsRejectInvalidDefaultCronExpressions(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SchedulerTaskDefinition::command(
            'system.bad_cron',
            'admin.scheduler.tasks.bad.label',
            'admin.scheduler.tasks.bad.description',
            'studio:test',
            'not a cron',
        );
    }

    public function testTaskDefinitionsAcceptShortPackageSources(): void
    {
        $definition = SchedulerTaskDefinition::command(
            'ai.cleanup',
            'admin.scheduler.tasks.test.label',
            'admin.scheduler.tasks.test.description',
            'studio:test',
            '* * * * *',
            'ai',
            false,
        );

        self::assertSame('ai', $definition->source());
    }

    public function testTaskDefinitionsRejectInvalidMetadata(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SchedulerTaskDefinition(
            'system.bad_metadata',
            'admin.scheduler.tasks.bad.label',
            'admin.scheduler.tasks.bad.description',
            'system',
            SchedulerTaskType::Command,
            'studio:test',
            '* * * * *',
            true,
            ['resource' => fopen('php://memory', 'r')],
        );
    }

    private function synchronizer(?SchedulerTaskProviderInterface $provider = null): SchedulerTaskSynchronizer
    {
        return new SchedulerTaskSynchronizer(
            new SchedulerTaskRegistry([$provider ?? new TestSchedulerTaskProvider()]),
            $this->entityManager,
            new SchedulerSettings(new Config($this->entityManager->getConnection())),
        );
    }

    private function runner(
        SchedulerTaskExecutorInterface $executor,
        ?SchedulerTaskProviderInterface $provider = null,
        ?ActivePackageProviderInterface $activePackageProvider = null,
    ): SchedulerRunner
    {
        return new SchedulerRunner(
            new SchedulerSettings(new Config($this->entityManager->getConnection())),
            $this->synchronizer($provider),
            $this->entityManager,
            [$executor],
            new SchedulerLockFactory(
                new LockFactory(new FlockStore(sys_get_temp_dir().'/studio-scheduler-test-'.bin2hex(random_bytes(4)))),
                'test',
            ),
            new UuidFactory(),
            new TestSchedulerMessageLogger(),
            $activePackageProvider ?? new TestActivePackageProvider(),
        );
    }
}

final readonly class TestSchedulerTaskProvider implements SchedulerTaskProviderInterface
{
    public function schedulerTasks(): array
    {
        return [
            SchedulerTaskDefinition::command(
                'system.test_task',
                'admin.scheduler.tasks.test.label',
                'admin.scheduler.tasks.test.description',
                'studio:test',
                '* * * * *',
            ),
        ];
    }
}

final readonly class TestMultipleSchedulerTaskProvider implements SchedulerTaskProviderInterface
{
    public function schedulerTasks(): array
    {
        return [
            SchedulerTaskDefinition::command(
                'system.first_task',
                'admin.scheduler.tasks.test.label',
                'admin.scheduler.tasks.test.description',
                'studio:test',
                '* * * * *',
            ),
            SchedulerTaskDefinition::command(
                'system.second_task',
                'admin.scheduler.tasks.test.label',
                'admin.scheduler.tasks.test.description',
                'studio:test',
                '* * * * *',
            ),
        ];
    }
}

final readonly class TestMixedSchedulerTaskProvider implements SchedulerTaskProviderInterface
{
    public function schedulerTasks(): array
    {
        return [
            SchedulerTaskDefinition::command(
                'system.first_task',
                'admin.scheduler.tasks.test.label',
                'admin.scheduler.tasks.test.description',
                'studio:test',
                '* * * * *',
            ),
            new SchedulerTaskDefinition(
                'demo.command',
                'admin.scheduler.tasks.demo.label',
                'admin.scheduler.tasks.demo.description',
                'demo-package',
                SchedulerTaskType::Command,
                'studio:test',
                '* * * * *',
                false,
            ),
        ];
    }
}

final readonly class TestSchedulerTaskExecutor implements SchedulerTaskExecutorInterface
{
    public function __construct(private bool $success)
    {
    }

    public function supports(SchedulerTask $task): bool
    {
        return true;
    }

    public function execute(SchedulerTask $task): SchedulerTaskExecution
    {
        return $this->success
            ? SchedulerTaskExecution::success(['test' => true])
            : SchedulerTaskExecution::failed(['test' => false]);
    }
}

final readonly class TestDelayedSchedulerTaskExecutor implements SchedulerTaskExecutorInterface
{
    public function supports(SchedulerTask $task): bool
    {
        return true;
    }

    public function execute(SchedulerTask $task): SchedulerTaskExecution
    {
        if ('system.first_task' === $task->identifier()) {
            usleep(150000);
        }

        return SchedulerTaskExecution::success(['test' => true]);
    }
}

final readonly class TestInvalidContextSchedulerTaskExecutor implements SchedulerTaskExecutorInterface
{
    public function supports(SchedulerTask $task): bool
    {
        return true;
    }

    public function execute(SchedulerTask $task): SchedulerTaskExecution
    {
        return SchedulerTaskExecution::success(['resource' => fopen('php://memory', 'r')]);
    }
}

final readonly class TestPackageDeactivatingSchedulerTaskExecutor implements SchedulerTaskExecutorInterface
{
    public function __construct(private MutableActivePackageProvider $activePackageProvider)
    {
    }

    public function supports(SchedulerTask $task): bool
    {
        return true;
    }

    public function execute(SchedulerTask $task): SchedulerTaskExecution
    {
        if ('system.first_task' === $task->identifier()) {
            $this->activePackageProvider->deactivate('demo-package');
        }

        return SchedulerTaskExecution::success(['test' => true]);
    }
}

final readonly class TestPackageActionQueueSchedulerTaskProvider implements SchedulerTaskProviderInterface
{
    public function schedulerTasks(): array
    {
        return [
            new SchedulerTaskDefinition(
                'demo.action_queue',
                'admin.scheduler.tasks.demo.label',
                'admin.scheduler.tasks.demo.description',
                'demo-package',
                SchedulerTaskType::ActionQueue,
                'demo.queue',
                '* * * * *',
                false,
            ),
        ];
    }
}

final readonly class TestPackageCommandSchedulerTaskProvider implements SchedulerTaskProviderInterface
{
    public function schedulerTasks(): array
    {
        return [
            new SchedulerTaskDefinition(
                'demo.command',
                'admin.scheduler.tasks.demo.label',
                'admin.scheduler.tasks.demo.description',
                'demo-package',
                SchedulerTaskType::Command,
                'studio:test',
                '* * * * *',
                false,
            ),
        ];
    }
}

final readonly class TestCollidingPackageSchedulerTaskProvider implements SchedulerTaskProviderInterface
{
    public function schedulerTasks(): array
    {
        return [
            new SchedulerTaskDefinition(
                'system.test_task',
                'admin.scheduler.tasks.demo.label',
                'admin.scheduler.tasks.demo.description',
                'demo-package',
                SchedulerTaskType::Command,
                'studio:demo:test',
                '* * * * *',
                false,
            ),
        ];
    }
}

final class TestSchedulerMessageLogger implements MessageLoggerInterface
{
    public function log(Message $message, array $context = []): void
    {
    }

    public function logBatch(iterable $records): void
    {
    }
}

final readonly class TestActivePackageProvider implements ActivePackageProviderInterface
{
    public function packages(?PackageScope $scope = null): array
    {
        return [];
    }

    public function package(string $packageName): ?ExtensionPackage
    {
        return null;
    }
}

final class MutableActivePackageProvider implements ActivePackageProviderInterface
{
    /**
     * @param list<string> $packageNames
     */
    public function __construct(private array $packageNames)
    {
    }

    public function deactivate(string $packageName): void
    {
        $this->packageNames = array_values(array_filter(
            $this->packageNames,
            static fn (string $activePackage): bool => $activePackage !== $packageName,
        ));
    }

    public function packages(?PackageScope $scope = null): array
    {
        return array_map(static fn (string $packageName): ExtensionPackage => new ExtensionPackage(
            self::uuidFor($packageName),
            [PackageScope::Module],
            $packageName,
            'packages/'.str_replace('.', '-', $packageName),
            ExtensionPackageStatus::Active,
        ), $this->packageNames);
    }

    public function package(string $packageName): ?ExtensionPackage
    {
        foreach ($this->packages() as $package) {
            if ($package->packageName() === $packageName) {
                return $package;
            }
        }

        return null;
    }

    private static function uuidFor(string $value): string
    {
        return Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_DNS), $value)->toRfc4122();
    }
}
