<?php

declare(strict_types=1);

namespace App\Tests\Core\Messenger;

use App\Core\Messenger\DeferredMessengerDrain;
use App\Core\Messenger\DeferredMessengerDrainSubscriber;
use App\Core\Messenger\DeferredMessengerDrainStarterInterface;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Log\MessageLoggerInterface;
use App\Core\Message\Message;
use App\Scheduler\SchedulerSettings;
use App\Tests\Support\FilesystemTestHelper;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class DeferredMessengerDrainTest extends TestCase
{
    use FilesystemTestHelper;

    public function testItStartsDetachedWorkerWhenPendingMessagesExist(): void
    {
        $projectDir = $this->createTemporaryProjectDirectory('messenger-drain');
        $connection = $this->connectionWithMessengerTable();
        $starter = new RecordingDeferredMessengerStarter();
        $this->insertMessage($connection, 'default');

        $drain = new DeferredMessengerDrain($connection, $starter, $projectDir, 'test', transportDsn: 'doctrine://default?auto_setup=0');

        self::assertTrue($drain->drainPendingMessages());
        self::assertCount(1, $starter->starts);
        self::assertContains('messenger:consume', $starter->starts[0]['command']);
        self::assertContains('async', $starter->starts[0]['command']);
        self::assertContains('--env=test', $starter->starts[0]['command']);
        self::assertStringEndsWith('/var/log/test/messenger-drain.log', $this->portablePath($starter->starts[0]['output_path']));
        self::assertStringEndsWith('/var/cache/test/system-messenger-drain.pid', $this->portablePath($starter->starts[0]['pid_path']));

        $this->removeDirectory($projectDir);
    }

    public function testItDoesNotStartWorkerWithoutPendingMessages(): void
    {
        $projectDir = $this->createTemporaryProjectDirectory('messenger-drain-empty');
        $connection = $this->connectionWithMessengerTable();
        $starter = new RecordingDeferredMessengerStarter();

        $drain = new DeferredMessengerDrain($connection, $starter, $projectDir, 'test');

        self::assertFalse($drain->drainPendingMessages());
        self::assertSame([], $starter->starts);

        $this->removeDirectory($projectDir);
    }

    public function testItUsesCooldownLockToAvoidParallelWorkers(): void
    {
        $projectDir = $this->createTemporaryProjectDirectory('messenger-drain-lock');
        $connection = $this->connectionWithMessengerTable();
        $starter = new RecordingDeferredMessengerStarter();
        $this->insertMessage($connection, 'async');

        $drain = new DeferredMessengerDrain($connection, $starter, $projectDir, 'test', transportDsn: 'doctrine://default?queue_name=async', cooldownSeconds: 300);

        self::assertTrue($drain->drainPendingMessages());
        self::assertFalse($drain->drainPendingMessages());
        self::assertCount(1, $starter->starts);

        $this->removeDirectory($projectDir);
    }

    public function testItStartsDetachedSchedulerWhenWebTriggerIsEnabled(): void
    {
        $projectDir = $this->createTemporaryProjectDirectory('messenger-drain-scheduler');
        $connection = $this->connectionWithMessengerTable();
        $starter = new RecordingDeferredMessengerStarter();
        $settings = $this->schedulerSettings($connection, true);

        $drain = new DeferredMessengerDrain($connection, $starter, $projectDir, 'test', schedulerSettings: $settings);

        self::assertTrue($drain->drainPendingMessages());
        self::assertCount(1, $starter->starts);
        self::assertSame(PHP_BINARY, $starter->starts[0]['command'][0]);
        self::assertStringEndsWith('/bin/scheduler', $this->portablePath($starter->starts[0]['command'][1]));
        self::assertContains('--json', $starter->starts[0]['command']);
        self::assertContains('--env=test', $starter->starts[0]['command']);
        self::assertStringEndsWith('/var/log/test/scheduler-web-trigger.log', $this->portablePath($starter->starts[0]['output_path']));
        self::assertStringEndsWith('/var/cache/test/system-scheduler-web-trigger.pid', $this->portablePath($starter->starts[0]['pid_path']));

        $this->removeDirectory($projectDir);
    }

    public function testItUsesSharedCooldownForWebTriggeredScheduler(): void
    {
        $projectDir = $this->createTemporaryProjectDirectory('messenger-drain-scheduler-lock');
        $connection = $this->connectionWithMessengerTable();
        $starter = new RecordingDeferredMessengerStarter();
        $settings = $this->schedulerSettings($connection, true);

        $drain = new DeferredMessengerDrain($connection, $starter, $projectDir, 'test', cooldownSeconds: 60, schedulerSettings: $settings);

        self::assertTrue($drain->drainPendingMessages());
        self::assertFalse($drain->drainPendingMessages());
        self::assertCount(1, $starter->starts);

        $this->removeDirectory($projectDir);
    }

    public function testSubscriberSkipsSchedulerCronRequests(): void
    {
        $projectDir = $this->createTemporaryProjectDirectory('messenger-drain-scheduler-route');
        $connection = $this->connectionWithMessengerTable();
        $starter = new RecordingDeferredMessengerStarter();
        $settings = $this->schedulerSettings($connection, true);
        $drain = new DeferredMessengerDrain($connection, $starter, $projectDir, 'test', schedulerSettings: $settings);
        $request = Request::create('/cron/run');
        $request->attributes->set('_route', 'scheduler_cron_run');

        (new DeferredMessengerDrainSubscriber($drain))->onKernelTerminate(new TerminateEvent(
            new class implements HttpKernelInterface {
                public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
                {
                    return new Response();
                }
            },
            $request,
            new Response(),
        ));

        self::assertSame([], $starter->starts);

        $this->removeDirectory($projectDir);
    }

    public function testSubscriberDoesNotSkipSchedulerLookalikeRequests(): void
    {
        $projectDir = $this->createTemporaryProjectDirectory('messenger-drain-scheduler-lookalike');
        $connection = $this->connectionWithMessengerTable();
        $starter = new RecordingDeferredMessengerStarter();
        $settings = $this->schedulerSettings($connection, true);
        $drain = new DeferredMessengerDrain($connection, $starter, $projectDir, 'test', schedulerSettings: $settings);
        $request = Request::create('/cron/runaway');

        (new DeferredMessengerDrainSubscriber($drain))->onKernelTerminate(new TerminateEvent(
            new class implements HttpKernelInterface {
                public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
                {
                    return new Response();
                }
            },
            $request,
            new Response(),
        ));

        self::assertCount(1, $starter->starts);

        $this->removeDirectory($projectDir);
    }

    public function testItLogsDispatchFailureWhenDetachedStartFails(): void
    {
        $projectDir = $this->createTemporaryProjectDirectory('messenger-drain-scheduler-failure');
        $connection = $this->connectionWithMessengerTable();
        $starter = new RecordingDeferredMessengerStarter(false);
        $logger = new RecordingMessageLogger();
        $settings = $this->schedulerSettings($connection, true);

        $drain = new DeferredMessengerDrain(
            $connection,
            $starter,
            $projectDir,
            'test',
            schedulerSettings: $settings,
            messageLogger: $logger,
        );

        self::assertFalse($drain->drainPendingMessages());
        self::assertCount(1, $logger->messages);
        self::assertSame('messenger.deferred_process_start_failed', $logger->messages[0]->code());

        $this->removeDirectory($projectDir);
    }

    public function testSchedulerStartDoesNotMaskMessengerStartFailure(): void
    {
        $projectDir = $this->createTemporaryProjectDirectory('messenger-drain-messenger-failure');
        $connection = $this->connectionWithMessengerTable();
        $starter = new RecordingDeferredMessengerStarter(false);
        $logger = new RecordingMessageLogger();
        $settings = $this->schedulerSettings($connection, true);
        $this->insertMessage($connection, 'default');

        $drain = new DeferredMessengerDrain(
            $connection,
            $starter,
            $projectDir,
            'test',
            transportDsn: 'doctrine://default?auto_setup=0',
            schedulerSettings: $settings,
            messageLogger: $logger,
        );

        self::assertFalse($drain->drainPendingMessages());
        self::assertCount(1, $starter->starts);
        self::assertContains('messenger:consume', $starter->starts[0]['command']);
        self::assertCount(1, $logger->messages);
        self::assertSame('messenger.deferred_process_start_failed', $logger->messages[0]->code());

        $this->removeDirectory($projectDir);
    }

    public function testMessengerStartSucceedsEvenWhenSchedulerStartFails(): void
    {
        $projectDir = $this->createTemporaryProjectDirectory('messenger-drain-scheduler-start-failure');
        $connection = $this->connectionWithMessengerTable();
        $starter = new RecordingDeferredMessengerStarter(failCommandsContaining: 'bin/scheduler');
        $logger = new RecordingMessageLogger();
        $settings = $this->schedulerSettings($connection, true);
        $this->insertMessage($connection, 'default');

        $drain = new DeferredMessengerDrain(
            $connection,
            $starter,
            $projectDir,
            'test',
            transportDsn: 'doctrine://default?auto_setup=0',
            schedulerSettings: $settings,
            messageLogger: $logger,
        );

        self::assertTrue($drain->drainPendingMessages());
        self::assertCount(2, $starter->starts);
        self::assertContains('messenger:consume', $starter->starts[0]['command']);
        self::assertSame(PHP_BINARY, $starter->starts[1]['command'][0]);
        self::assertStringEndsWith('/bin/scheduler', $this->portablePath($starter->starts[1]['command'][1]));
        self::assertCount(1, $logger->messages);
        self::assertSame('messenger.deferred_process_start_failed', $logger->messages[0]->code());

        $this->removeDirectory($projectDir);
    }

    public function testItUsesConfiguredDoctrineQueueNameForPendingCheck(): void
    {
        $projectDir = $this->createTemporaryProjectDirectory('messenger-drain-queue-name');
        $connection = $this->connectionWithMessengerTable();
        $starter = new RecordingDeferredMessengerStarter();
        $this->insertMessage($connection, 'custom_queue');

        $drain = new DeferredMessengerDrain($connection, $starter, $projectDir, 'test', transportDsn: 'doctrine://default?auto_setup=0&queue_name=custom_queue');

        self::assertTrue($drain->drainPendingMessages());
        self::assertCount(1, $starter->starts);
        self::assertContains('async', $starter->starts[0]['command']);

        $this->removeDirectory($projectDir);
    }

    public function testItSilentlySkipsWhenMessengerTableDoesNotExist(): void
    {
        $projectDir = $this->createTemporaryProjectDirectory('messenger-drain-missing-table');
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $starter = new RecordingDeferredMessengerStarter();

        $drain = new DeferredMessengerDrain($connection, $starter, $projectDir, 'test');

        self::assertFalse($drain->drainPendingMessages());
        self::assertSame([], $starter->starts);

        $this->removeDirectory($projectDir);
    }

    private function createTemporaryProjectDirectory(string $prefix): string
    {
        $projectDir = $this->createTemporaryDirectory($prefix);
        $this->writeTestFile($projectDir, 'bin/console', "#!/usr/bin/env php\n<?php echo \"Studio test\";\n");

        return $projectDir;
    }

    private function connectionWithMessengerTable(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(<<<SQL
            CREATE TABLE messenger_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                body TEXT NOT NULL,
                headers TEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at DATETIME NOT NULL,
                available_at DATETIME NOT NULL,
                delivered_at DATETIME DEFAULT NULL
            )
            SQL);
        $connection->executeStatement(<<<SQL
            CREATE TABLE config_entry (
                config_key VARCHAR(190) NOT NULL PRIMARY KEY,
                value TEXT NOT NULL,
                value_type VARCHAR(32) NOT NULL,
                sensitive BOOLEAN NOT NULL DEFAULT 0,
                modified_at DATETIME NOT NULL,
                modified_by VARCHAR(190) DEFAULT NULL
            )
            SQL);

        return $connection;
    }

    private function schedulerSettings(Connection $connection, bool $webTriggerEnabled): SchedulerSettings
    {
        $config = new Config($connection);
        $config->set(SchedulerSettings::ENABLED_KEY, true, ConfigValueType::Boolean);
        $config->set(SchedulerSettings::WEB_TRIGGER_ENABLED_KEY, $webTriggerEnabled, ConfigValueType::Boolean);

        return new SchedulerSettings($config);
    }

    private function insertMessage(Connection $connection, string $queue): void
    {
        $connection->insert('messenger_messages', [
            'body' => '{}',
            'headers' => '{}',
            'queue_name' => $queue,
            'created_at' => (new DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'),
            'available_at' => (new DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'),
            'delivered_at' => null,
        ]);
    }

    private function portablePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}

final class RecordingDeferredMessengerStarter implements DeferredMessengerDrainStarterInterface
{
    /**
     * @var list<array{command: list<string>, cwd: string, output_path: string, pid_path: string}>
     */
    public array $starts = [];

    public function __construct(private bool $success = true, private ?string $failCommandsContaining = null)
    {
    }

    public function start(array $command, string $cwd, string $outputPath, string $pidPath): bool
    {
        $this->starts[] = [
            'command' => $command,
            'cwd' => $cwd,
            'output_path' => $outputPath,
            'pid_path' => $pidPath,
        ];

        if (null !== $this->failCommandsContaining && str_contains(implode(' ', $command), $this->failCommandsContaining)) {
            return false;
        }

        return $this->success;
    }
}

final class RecordingMessageLogger implements MessageLoggerInterface
{
    /**
     * @var list<Message>
     */
    public array $messages = [];

    public function log(Message $message, array $context = []): void
    {
        $this->messages[] = $message;
    }

    public function logBatch(iterable $records): void
    {
        foreach ($records as $record) {
            if ($record instanceof Message) {
                $this->messages[] = $record;
            }
        }
    }
}
