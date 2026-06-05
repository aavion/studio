<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AccessStatisticsSnapshotCommand;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Console\ConsoleResultRenderer;
use App\Core\Statistics\AccessStatisticsAggregator;
use App\Core\Statistics\AccessStatisticsPolicy;
use App\Core\Statistics\AccessStatisticsSnapshotProvider;
use App\Core\Statistics\AccessStatisticsStoreInterface;
use App\Core\Statistics\AccessStatisticsWindow;
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
        self::getContainer()->get(Config::class)->set(AccessStatisticsPolicy::ENABLED_KEY, true, ConfigValueType::Boolean);
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

    public function testItFailsWhenSnapshotCannotBeStored(): void
    {
        $policy = self::getContainer()->get(AccessStatisticsPolicy::class);
        $window = self::getContainer()->get(AccessStatisticsWindow::class);
        $provider = new AccessStatisticsSnapshotProvider(
            self::getContainer()->get(AccessStatisticsAggregator::class),
            new FailingAccessStatisticsStore(),
            $window,
            $policy,
        );
        $tester = new CommandTester(new AccessStatisticsSnapshotCommand($provider, $policy, $window, new ConsoleResultRenderer()));

        $exitCode = $tester->execute(['--json' => true, '--window' => '24h']);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame('failed', $payload['status']);
        self::assertSame('snapshot_store_failed', $payload['reason']);
        self::assertSame('24h', $payload['window']);
    }

    private function removeSnapshot(): void
    {
        if (is_file($this->snapshotPath)) {
            unlink($this->snapshotPath);
        }
    }
}

final class FailingAccessStatisticsStore implements AccessStatisticsStoreInterface
{
    public function saveLatest(array $snapshot): bool
    {
        return false;
    }

    public function latest(): ?array
    {
        return null;
    }
}
