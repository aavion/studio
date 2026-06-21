<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ExtensionDiscoveryCommand;
use App\Core\Console\ConsoleResultRenderer;
use App\Core\Extension\ExtensionDiscovery;
use App\Core\Extension\ExtensionDiscoveryDispatcher;
use App\Core\Extension\ExtensionDiscoveryMessage;
use App\Core\Extension\ExtensionDiscoveryRunner;
use App\Core\Extension\ExtensionRegistryHandler;
use App\Tests\Support\FilesystemTestHelper;
use App\Tests\Support\IdentityTranslator;
use App\Tests\Support\RecordingMessageBus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use App\Tests\Support\NullWorkflowResultMessageReporter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ExtensionDiscoveryCommandTest extends KernelTestCase
{
    use FilesystemTestHelper;

    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private string $projectDir;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->projectDir = $this->createTemporaryDirectory('system-extension-discovery-command');
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        foreach (['api-extension-action', 'api-extension-denied', 'api-extension-detail', 'api-extension-owner', 'api-extension-readonly', 'icon-captcha'] as $extensionName) {
            $this->connection->delete('extension', ['extension_name' => $extensionName]);
        }
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

    public function testItQueuesExtensionDiscoveryAsManualJsonCommand(): void
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
        self::assertInstanceOf(ExtensionDiscoveryMessage::class, $messageBus->messages()[0]);
        self::assertSame('admin_refresh', $messageBus->messages()[0]->trigger());
    }

    public function testItCanRunExtensionDiscoverySynchronouslyForRecovery(): void
    {
        $this->writeTestFile($this->projectDir, 'extensions/demo-module/.manifest', <<<MANIFEST
            EXTENSION_AUTHOR=Aavion
            EXTENSION_SLUG=demo-module
            EXTENSION_NAME=Demo Module
            EXTENSION_VERSION=1.0.0
            EXTENSION_SCOPE=module
            EXTENSION_DEPENDENCIES=[]
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
            'extension' => 'demo-module',
            'action' => 'registered',
            'status' => 'inactive',
        ]], $payload['value']['changes']);
    }

    private function command(RecordingMessageBus $messageBus): ExtensionDiscoveryCommand
    {
        return new ExtensionDiscoveryCommand(
            new ExtensionDiscoveryDispatcher($messageBus, new NullWorkflowResultMessageReporter()),
            new ExtensionDiscoveryRunner(
                new ExtensionDiscovery(),
                new ExtensionRegistryHandler($this->entityManager, $this->projectDir),
                $this->projectDir,
                'test',
                new NullWorkflowResultMessageReporter(),
            ),
            new IdentityTranslator(),
            new ConsoleResultRenderer(),
        );
    }
}
