<?php

declare(strict_types=1);

namespace App\Tests\Scheduler;

use App\Core\Config\Config;
use App\Core\Id\UuidFactory;
use App\Core\Log\MessageLoggerInterface;
use App\Core\Message\Message;
use App\Core\Message\MessageException;
use App\Core\Extension\ActiveExtensionProviderInterface;
use App\Core\Extension\ExtensionStatus;
use App\Core\Extension\ExtensionScope;
use App\Entity\Extension;
use App\Entity\SchedulerTask;
use App\Entity\SchedulerTaskRun;
use App\Scheduler\CallableSchedulerTaskExecutor;
use App\Scheduler\SchedulerCallableProviderInterface;
use App\Scheduler\SchedulerDueTaskSelector;
use App\Scheduler\SchedulerLockFactory;
use App\Scheduler\SchedulerRunReporter;
use App\Scheduler\SchedulerRunner;
use App\Scheduler\SchedulerSettings;
use App\Scheduler\SchedulerTaskRunRecorder;
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

    public function testItHidesUntrustedExtensionActionQueuesWhenDisabled(): void
    {
        $tasks = $this->synchronizer(new TestExtensionActionQueueSchedulerTaskProvider())->synchronize();

        self::assertSame([], $tasks);
    }

    public function testItReportsForcedExtensionActionQueueTaskAsSkippedWhenPolicyBlocksIt(): void
    {
        $task = new SchedulerTask(new SchedulerTaskDefinition(
            'demo.action_queue',
            'admin.scheduler.tasks.demo.label',
            'admin.scheduler.tasks.demo.description',
            'demo-extension',
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
            new TestExtensionActionQueueSchedulerTaskProvider(),
            new MutableActiveExtensionProvider(['demo-extension']),
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
            new TestCollidingExtensionSchedulerTaskProvider(),
            new TestSchedulerTaskProvider(),
        ]);

        self::assertSame('system', $registry->definition('system.test_task')?->source());
    }

    public function testItDoesNotShowTasksWhoseExtensionNoLongerRegistersThem(): void
    {
        $staleTask = new SchedulerTask(new SchedulerTaskDefinition(
            'demo.stale_task',
            'admin.scheduler.tasks.demo.label',
            'admin.scheduler.tasks.demo.description',
            'demo-extension',
            SchedulerTaskType::Command,
            'demo:test',
            '* * * * *',
            false,
        ));
        $staleTask->activate('* * * * *');
        $this->entityManager->persist($staleTask);
        $this->entityManager->flush();

        $tasks = $this->synchronizer()->synchronize();

        self::assertSame(['system.test_task'], array_map(static fn (SchedulerTask $task): string => $task->identifier(), $tasks));
    }

    public function testItDoesNotRunActiveDueTasksFromInactiveExtensions(): void
    {
        $this->synchronizer(new TestExtensionCommandSchedulerTaskProvider())->synchronize();
        $task = $this->entityManager->find(SchedulerTask::class, 'demo.command');
        self::assertInstanceOf(SchedulerTask::class, $task);
        $task->activate('* * * * *');
        $this->entityManager->flush();

        $payload = $this->runner(new TestSchedulerTaskExecutor(true), new TestExtensionCommandSchedulerTaskProvider())->run()->toArray();

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

    public function testItRedactsSchedulerRunContextBeforePersisting(): void
    {
        $this->synchronizer()->synchronize();
        $task = $this->entityManager->find(SchedulerTask::class, 'system.test_task');
        self::assertInstanceOf(SchedulerTask::class, $task);
        $task->activate('* * * * *');
        $this->entityManager->flush();

        $result = $this->runner(new TestSensitiveContextSchedulerTaskExecutor())->run('system.test_task', true);

        self::assertSame('completed', $result->toArray()['status']);
        $updated = $this->entityManager->find(SchedulerTask::class, 'system.test_task');
        self::assertInstanceOf(SchedulerTask::class, $updated);
        $runs = $this->entityManager->getRepository(SchedulerTaskRun::class)->findBy(['task' => $updated]);
        self::assertCount(1, $runs);

        $context = $runs[0]->context();
        self::assertSame(SchedulerTaskRunStatus::Failed, $runs[0]->status());
        self::assertSame('[redacted]', $context['command']);
        self::assertSame('[redacted]', $context['command_line']);
        self::assertSame('[redacted]', $context['cwd']);
        self::assertSame('[redacted]', $context['message']);
        self::assertSame('[redacted]', $context['exception_message']);
        self::assertSame('[redacted]', $context['previous_message']);
        self::assertSame('[redacted]', $context['output_excerpt']);
        self::assertSame('[redacted]', $context['error_excerpt']);
        self::assertSame('[redacted]', $context['headers']['authorization']);
        self::assertSame(7, $context['exit_code']);
        self::assertSame('failed', $context['reason']);
    }

    public function testItRedactsThrownCallableSchedulerMessagesBeforePersisting(): void
    {
        $this->synchronizer(new TestCallableSchedulerTaskProvider())->synchronize();
        $task = $this->entityManager->find(SchedulerTask::class, 'system.callable_task');
        self::assertInstanceOf(SchedulerTask::class, $task);
        $task->activate('* * * * *');
        $this->entityManager->flush();

        $executor = new CallableSchedulerTaskExecutor([new TestThrowingSchedulerCallableProvider()]);
        $result = $this->runner($executor, new TestCallableSchedulerTaskProvider())->run('system.callable_task', true);

        self::assertSame('completed', $result->toArray()['status']);
        $updated = $this->entityManager->find(SchedulerTask::class, 'system.callable_task');
        self::assertInstanceOf(SchedulerTask::class, $updated);
        $runs = $this->entityManager->getRepository(SchedulerTaskRun::class)->findBy(['task' => $updated]);
        self::assertCount(1, $runs);

        $context = $runs[0]->context();
        self::assertSame(SchedulerTaskRunStatus::Failed, $runs[0]->status());
        self::assertSame('system.secret_callable', $context['target']);
        self::assertSame(\RuntimeException::class, $context['exception']);
        self::assertSame('[redacted]', $context['message']);
    }

    public function testItRedactsUnsupportedRunContextBeforePersisting(): void
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
        self::assertSame('success', $payload['tasks'][0]['status']);
        self::assertSame('active', $payload['tasks'][0]['task_status']);
        self::assertSame(0, $updated->failureCount());

        $runs = $this->entityManager->getRepository(SchedulerTaskRun::class)->findBy(['task' => $updated]);
        self::assertCount(1, $runs);
        self::assertSame(SchedulerTaskRunStatus::Success, $runs[0]->status());
        self::assertSame('[unsupported]', $runs[0]->context()['resource']);
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
        $activeExtensions = new MutableActiveExtensionProvider(['demo-extension']);
        $this->synchronizer(new TestMixedSchedulerTaskProvider())->synchronize();

        foreach (['system.first_task' => '-2 minutes', 'demo.command' => '-1 minute'] as $identifier => $dueAt) {
            $task = $this->entityManager->find(SchedulerTask::class, $identifier);
            self::assertInstanceOf(SchedulerTask::class, $task);
            $task->activate('* * * * *');
            $task->seedNextDue(new \DateTimeImmutable($dueAt));
        }
        $this->entityManager->flush();

        $payload = $this->runner(
            new TestExtensionDeactivatingSchedulerTaskExecutor($activeExtensions),
            new TestMixedSchedulerTaskProvider(),
            $activeExtensions,
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
        $runs = array_values(array_filter(
            $this->entityManager->getRepository(SchedulerTaskRun::class)->findBy([], ['startedAt' => 'ASC']),
            static fn (SchedulerTaskRun $run): bool => in_array($run->task()->identifier(), [
                'system.first_task',
                'system.second_task',
            ], true),
        ));

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
            'demo:test',
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
            'demo:test',
            '* * * * *',
        ));
        $task->seedNextDue(new \DateTimeImmutable('+1 hour'));

        $task->syncDefinition(SchedulerTaskDefinition::command(
            'system.sync_task',
            'admin.scheduler.tasks.sync.label',
            'admin.scheduler.tasks.sync.description',
            'demo:test',
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
            'demo:test',
            '* * * * *',
            'demo-extension',
            false,
        ));
        $task->activate('* * * * *');

        $task->syncDefinition(new SchedulerTaskDefinition(
            'demo.sync_task',
            'admin.scheduler.tasks.sync.label',
            'admin.scheduler.tasks.sync.description',
            'demo-extension',
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
            'demo-extension',
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
            'demo-extension',
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
        $this->expectException(MessageException::class);
        $this->expectExceptionMessage('message.scheduler.task_definition.translation_key_invalid');

        SchedulerTaskDefinition::command(
            'system.bad_task',
            '<script>alert(1)</script>',
            'admin.scheduler.tasks.bad.description',
            'demo:test',
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
            'demo:test',
            'not a cron',
        );
    }

    public function testTaskDefinitionsAcceptShortExtensionSources(): void
    {
        $definition = SchedulerTaskDefinition::command(
            'ai.cleanup',
            'admin.scheduler.tasks.test.label',
            'admin.scheduler.tasks.test.description',
            'demo:test',
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
            'demo:test',
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
        ?ActiveExtensionProviderInterface $activeExtensionProvider = null,
    ): SchedulerRunner
    {
        $settings = new SchedulerSettings(new Config($this->entityManager->getConnection()));
        $activeExtensions = $activeExtensionProvider ?? new TestActiveExtensionProvider();
        $messageLogger = new TestSchedulerMessageLogger();
        $reporter = new SchedulerRunReporter($messageLogger);

        return new SchedulerRunner(
            $settings,
            $this->synchronizer($provider),
            new SchedulerLockFactory(
                new LockFactory(new FlockStore(sys_get_temp_dir().'/system-scheduler-test-'.bin2hex(random_bytes(4)))),
                'test',
            ),
            new SchedulerDueTaskSelector($settings, $activeExtensions),
            new SchedulerTaskRunRecorder($this->entityManager, [$executor], new UuidFactory(), $reporter),
            $reporter,
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
                'demo:test',
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
                'demo:test',
                '* * * * *',
            ),
            SchedulerTaskDefinition::command(
                'system.second_task',
                'admin.scheduler.tasks.test.label',
                'admin.scheduler.tasks.test.description',
                'demo:test',
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
                'demo:test',
                '* * * * *',
            ),
            new SchedulerTaskDefinition(
                'demo.command',
                'admin.scheduler.tasks.demo.label',
                'admin.scheduler.tasks.demo.description',
                'demo-extension',
                SchedulerTaskType::Command,
                'demo:test',
                '* * * * *',
                false,
            ),
        ];
    }
}

final readonly class TestCallableSchedulerTaskProvider implements SchedulerTaskProviderInterface
{
    public function schedulerTasks(): array
    {
        return [
            new SchedulerTaskDefinition(
                'system.callable_task',
                'admin.scheduler.tasks.callable.label',
                'admin.scheduler.tasks.callable.description',
                'system',
                SchedulerTaskType::Callable,
                'system.secret_callable',
                '* * * * *',
                true,
            ),
        ];
    }
}

final readonly class TestThrowingSchedulerCallableProvider implements SchedulerCallableProviderInterface
{
    public function schedulerCallable(string $target): ?callable
    {
        if ('system.secret_callable' !== $target) {
            return null;
        }

        return static fn (): SchedulerTaskExecution => throw new \RuntimeException('/private/path failed with token abc');
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

final readonly class TestSensitiveContextSchedulerTaskExecutor implements SchedulerTaskExecutorInterface
{
    public function supports(SchedulerTask $task): bool
    {
        return true;
    }

    public function execute(SchedulerTask $task): SchedulerTaskExecution
    {
        return SchedulerTaskExecution::failed([
            'command' => ['php', 'bin/console', 'secret:rotate', '--token=abc'],
            'command_line' => "'php' 'bin/console' 'secret:rotate' '--token=abc'",
            'cwd' => '/secret/path',
            'message' => '/private/path failed with token abc',
            'exception_message' => '/private/path failed with token abc',
            'previous_message' => '/private/previous failed with token abc',
            'output_excerpt' => 'secret stdout',
            'error_excerpt' => 'secret stderr',
            'headers' => [
                'authorization' => 'Bearer abc',
            ],
            'exit_code' => 7,
            'reason' => 'failed',
        ]);
    }
}

final readonly class TestExtensionDeactivatingSchedulerTaskExecutor implements SchedulerTaskExecutorInterface
{
    public function __construct(private MutableActiveExtensionProvider $activeExtensionProvider)
    {
    }

    public function supports(SchedulerTask $task): bool
    {
        return true;
    }

    public function execute(SchedulerTask $task): SchedulerTaskExecution
    {
        if ('system.first_task' === $task->identifier()) {
            $this->activeExtensionProvider->deactivate('demo-extension');
        }

        return SchedulerTaskExecution::success(['test' => true]);
    }
}

final readonly class TestExtensionActionQueueSchedulerTaskProvider implements SchedulerTaskProviderInterface
{
    public function schedulerTasks(): array
    {
        return [
            new SchedulerTaskDefinition(
                'demo.action_queue',
                'admin.scheduler.tasks.demo.label',
                'admin.scheduler.tasks.demo.description',
                'demo-extension',
                SchedulerTaskType::ActionQueue,
                'demo.queue',
                '* * * * *',
                false,
            ),
        ];
    }
}

final readonly class TestExtensionCommandSchedulerTaskProvider implements SchedulerTaskProviderInterface
{
    public function schedulerTasks(): array
    {
        return [
            new SchedulerTaskDefinition(
                'demo.command',
                'admin.scheduler.tasks.demo.label',
                'admin.scheduler.tasks.demo.description',
                'demo-extension',
                SchedulerTaskType::Command,
                'demo:test',
                '* * * * *',
                false,
            ),
        ];
    }
}

final readonly class TestCollidingExtensionSchedulerTaskProvider implements SchedulerTaskProviderInterface
{
    public function schedulerTasks(): array
    {
        return [
            new SchedulerTaskDefinition(
                'system.test_task',
                'admin.scheduler.tasks.demo.label',
                'admin.scheduler.tasks.demo.description',
                'demo-extension',
                SchedulerTaskType::Command,
                'demo:test',
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

final readonly class TestActiveExtensionProvider implements ActiveExtensionProviderInterface
{
    public function extensions(?ExtensionScope $scope = null): array
    {
        return [];
    }

    public function extension(string $extensionName): ?Extension
    {
        return null;
    }
}

final class MutableActiveExtensionProvider implements ActiveExtensionProviderInterface
{
    /**
     * @param list<string> $extensionNames
     */
    public function __construct(private array $extensionNames)
    {
    }

    public function deactivate(string $extensionName): void
    {
        $this->extensionNames = array_values(array_filter(
            $this->extensionNames,
            static fn (string $activeExtension): bool => $activeExtension !== $extensionName,
        ));
    }

    public function extensions(?ExtensionScope $scope = null): array
    {
        return array_map(static fn (string $extensionName): Extension => new Extension(
            self::uuidFor($extensionName),
            [ExtensionScope::Module],
            $extensionName,
            'extensions/'.str_replace('.', '-', $extensionName),
            ExtensionStatus::Active,
        ), $this->extensionNames);
    }

    public function extension(string $extensionName): ?Extension
    {
        foreach ($this->extensions() as $extension) {
            if ($extension->extensionName() === $extensionName) {
                return $extension;
            }
        }

        return null;
    }

    private static function uuidFor(string $value): string
    {
        return Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_DNS), $value)->toRfc4122();
    }
}
