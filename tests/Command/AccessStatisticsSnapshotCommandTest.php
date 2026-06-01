<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AccessStatisticsSnapshotCommand;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Statistics\AccessStatisticsPolicy;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AccessStatisticsSnapshotCommandTest extends KernelTestCase
{
    private Connection $connection;
    private string $snapshotPath;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->connection->beginTransaction();
        $this->snapshotPath = dirname(__DIR__, 2).'/var/statistics/test/access/latest.json';
        $this->removeSnapshot();
    }

    protected function tearDown(): void
    {
        $this->removeSnapshot();

        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testItRefreshesStatisticsSnapshot(): void
    {
        $tester = new CommandTester(self::getContainer()->get(AccessStatisticsSnapshotCommand::class));

        $exitCode = $tester->execute(['--json' => true, '--window' => '24h']);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('success', $payload['status']);
        self::assertSame('24h', $payload['window']);
        self::assertFileExists($this->snapshotPath);
    }

    public function testItSkipsWhenStatisticsAreDisabled(): void
    {
        self::getContainer()->get(Config::class)->set(AccessStatisticsPolicy::ENABLED_KEY, false, ConfigValueType::Boolean);
        $tester = new CommandTester(self::getContainer()->get(AccessStatisticsSnapshotCommand::class));

        $exitCode = $tester->execute(['--json' => true]);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('skipped', $payload['status']);
        self::assertSame('statistics_disabled', $payload['reason']);
        self::assertFileDoesNotExist($this->snapshotPath);
    }

    private function removeSnapshot(): void
    {
        if (is_file($this->snapshotPath)) {
            unlink($this->snapshotPath);
        }
    }
}
