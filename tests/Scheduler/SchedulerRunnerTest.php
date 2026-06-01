<?php

declare(strict_types=1);

namespace App\Tests\Scheduler;

use App\Core\Config\Config;
use App\Core\Id\UuidFactory;
use App\Core\Log\MessageLoggerInterface;
use App\Core\Message\Message;
use App\Core\Package\ActivePackageProviderInterface;
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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

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

    private function synchronizer(): SchedulerTaskSynchronizer
    {
        return new SchedulerTaskSynchronizer(new SchedulerTaskRegistry([new TestSchedulerTaskProvider()]), $this->entityManager);
    }

    private function runner(SchedulerTaskExecutorInterface $executor): SchedulerRunner
    {
        return new SchedulerRunner(
            new SchedulerSettings(new Config($this->entityManager->getConnection())),
            $this->synchronizer(),
            $this->entityManager,
            [$executor],
            new SchedulerLockFactory(sys_get_temp_dir().'/studio-scheduler-test-'.bin2hex(random_bytes(4)), 'test'),
            new UuidFactory(),
            new TestSchedulerMessageLogger(),
            new TestActivePackageProvider(),
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
