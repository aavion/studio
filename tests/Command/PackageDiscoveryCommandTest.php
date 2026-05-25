<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PackageDiscoveryCommand;
use App\Core\Package\PackageDiscovery;
use App\Core\Package\PackageDiscoveryDispatcher;
use App\Core\Package\PackageDiscoveryMessage;
use App\Core\Package\PackageDiscoveryRunner;
use App\Core\Package\PackageRegistryHandler;
use App\Tests\Support\FilesystemTestHelper;
use App\Tests\Support\IdentityTranslator;
use App\Tests\Support\RecordingMessageBus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use App\Tests\Support\NullWorkflowResultMessageReporter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PackageDiscoveryCommandTest extends KernelTestCase
{
    use FilesystemTestHelper;

    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private string $projectDir;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->projectDir = $this->createTemporaryDirectory('studio-package-discovery-command');
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        $this->removeDirectory($this->projectDir);

        parent::tearDown();
    }

    public function testItQueuesPackageDiscoveryAsManualJsonCommand(): void
    {
        $messageBus = new RecordingMessageBus();
        $command = $this->command($messageBus);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--json' => true, '--trigger' => 'admin_refresh']);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('success', $payload['status']);
        self::assertSame('admin_refresh', $payload['context']['trigger']);
        self::assertTrue($payload['value']['deferred']);
        self::assertCount(1, $messageBus->messages());
        self::assertInstanceOf(PackageDiscoveryMessage::class, $messageBus->messages()[0]);
        self::assertSame('admin_refresh', $messageBus->messages()[0]->trigger());
    }

    public function testItCanRunPackageDiscoverySynchronouslyForRecovery(): void
    {
        $this->writeTestFile($this->projectDir, 'packages/demo-module/.manifest', <<<MANIFEST
            PACKAGE_AUTHOR=Aavion
            PACKAGE_NAME=Demo Module
            PACKAGE_VERSION=1.0.0
            PACKAGE_SCOPE=module
            PACKAGE_DEPENDENCIES=[]
            MANIFEST);

        $command = $this->command(new RecordingMessageBus());
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--json' => true, '--trigger' => 'admin_refresh', '--run-now' => true]);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('success', $payload['status']);
        self::assertSame('admin_refresh', $payload['context']['trigger']);
        self::assertSame(1, $payload['value']['candidate_count']);
        self::assertSame([[
            'package' => 'demo-module',
            'action' => 'registered',
            'status' => 'inactive',
        ]], $payload['value']['changes']);
    }

    private function command(RecordingMessageBus $messageBus): PackageDiscoveryCommand
    {
        return new PackageDiscoveryCommand(
            new PackageDiscoveryDispatcher($messageBus, new NullWorkflowResultMessageReporter()),
            new PackageDiscoveryRunner(
                new PackageDiscovery(),
                new PackageRegistryHandler($this->entityManager, $this->projectDir),
                $this->projectDir,
                'test',
                new NullWorkflowResultMessageReporter(),
            ),
            new IdentityTranslator(),
        );
    }
}
