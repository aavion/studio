<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SchedulerRunCommand;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SchedulerRunCommandTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
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
}
