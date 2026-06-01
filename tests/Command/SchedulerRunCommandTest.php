<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SchedulerRunCommand;
use App\Entity\SchedulerTask;
use App\Scheduler\SchedulerTaskDefinition;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SchedulerRunCommandTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testItRunsSchedulerAsJsonCommand(): void
    {
        $tester = new CommandTester(self::getContainer()->get(SchedulerRunCommand::class));

        $exitCode = $tester->execute(['--json' => true]);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('completed', $payload['status']);
        self::assertArrayHasKey('tasks', $payload);
    }

    public function testItRejectsUnknownJobIdentifiers(): void
    {
        $tester = new CommandTester(self::getContainer()->get(SchedulerRunCommand::class));

        $exitCode = $tester->execute(['--job' => 'system.missing']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Unknown scheduler job "system.missing".', $tester->getDisplay());
    }

    public function testItFailsWhenForcedTaskFails(): void
    {
        $definition = SchedulerTaskDefinition::command(
            'system.live_operation_cleanup',
            'admin.scheduler.tasks.live_operation_cleanup.label',
            'admin.scheduler.tasks.live_operation_cleanup.description',
            'studio:operations:cleanup',
            '*/15 * * * *',
        );
        $task = $this->entityManager->find(SchedulerTask::class, 'system.live_operation_cleanup') ?? new SchedulerTask($definition);
        $task->syncDefinition($definition, new \DateTimeImmutable());
        $task->activate('not a cron');
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        $tester = new CommandTester(self::getContainer()->get(SchedulerRunCommand::class));

        $exitCode = $tester->execute(['--job' => 'system.live_operation_cleanup', '--json' => true]);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame('failed', $payload['tasks'][0]['status']);
    }

    public function testItFailsWhenForcedTaskIsNotRunnable(): void
    {
        $definition = SchedulerTaskDefinition::command(
            'system.live_operation_cleanup',
            'admin.scheduler.tasks.live_operation_cleanup.label',
            'admin.scheduler.tasks.live_operation_cleanup.description',
            'studio:operations:cleanup',
            '*/15 * * * *',
        );
        $task = $this->entityManager->find(SchedulerTask::class, 'system.live_operation_cleanup') ?? new SchedulerTask($definition);
        $task->syncDefinition($definition, new \DateTimeImmutable());
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        $tester = new CommandTester(self::getContainer()->get(SchedulerRunCommand::class));

        $exitCode = $tester->execute(['--job' => 'system.live_operation_cleanup', '--json' => true]);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame('skipped', $payload['tasks'][0]['status']);
        self::assertSame('not_runnable', $payload['tasks'][0]['reason']);
    }
}
