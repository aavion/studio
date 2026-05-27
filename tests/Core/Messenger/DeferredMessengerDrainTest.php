<?php

declare(strict_types=1);

namespace App\Tests\Core\Messenger;

use App\Core\Messenger\DeferredMessengerDrain;
use App\Core\Messenger\DeferredMessengerDrainStarterInterface;
use App\Tests\Support\FilesystemTestHelper;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class DeferredMessengerDrainTest extends TestCase
{
    use FilesystemTestHelper;

    public function testItStartsDetachedWorkerWhenPendingMessagesExist(): void
    {
        $projectDir = $this->createTemporaryDirectory('messenger-drain');
        $connection = $this->connectionWithMessengerTable();
        $starter = new RecordingDeferredMessengerStarter();
        $this->insertMessage($connection, 'async');

        $drain = new DeferredMessengerDrain($connection, $starter, $projectDir, 'test');

        self::assertTrue($drain->drainPendingMessages());
        self::assertCount(1, $starter->starts);
        self::assertContains('messenger:consume', $starter->starts[0]['command']);
        self::assertContains('async', $starter->starts[0]['command']);
        self::assertContains('--env=test', $starter->starts[0]['command']);
        self::assertStringEndsWith('/var/log/test/messenger-drain.log', $starter->starts[0]['output_path']);
        self::assertStringEndsWith('/var/cache/test/studio-messenger-drain.pid', $starter->starts[0]['pid_path']);

        $this->removeDirectory($projectDir);
    }

    public function testItDoesNotStartWorkerWithoutPendingMessages(): void
    {
        $projectDir = $this->createTemporaryDirectory('messenger-drain-empty');
        $connection = $this->connectionWithMessengerTable();
        $starter = new RecordingDeferredMessengerStarter();

        $drain = new DeferredMessengerDrain($connection, $starter, $projectDir, 'test');

        self::assertFalse($drain->drainPendingMessages());
        self::assertSame([], $starter->starts);

        $this->removeDirectory($projectDir);
    }

    public function testItUsesCooldownLockToAvoidParallelWorkers(): void
    {
        $projectDir = $this->createTemporaryDirectory('messenger-drain-lock');
        $connection = $this->connectionWithMessengerTable();
        $starter = new RecordingDeferredMessengerStarter();
        $this->insertMessage($connection, 'async');

        $drain = new DeferredMessengerDrain($connection, $starter, $projectDir, 'test', cooldownSeconds: 300);

        self::assertTrue($drain->drainPendingMessages());
        self::assertFalse($drain->drainPendingMessages());
        self::assertCount(1, $starter->starts);

        $this->removeDirectory($projectDir);
    }

    public function testItSilentlySkipsWhenMessengerTableDoesNotExist(): void
    {
        $projectDir = $this->createTemporaryDirectory('messenger-drain-missing-table');
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $starter = new RecordingDeferredMessengerStarter();

        $drain = new DeferredMessengerDrain($connection, $starter, $projectDir, 'test');

        self::assertFalse($drain->drainPendingMessages());
        self::assertSame([], $starter->starts);

        $this->removeDirectory($projectDir);
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

        return $connection;
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
}

final class RecordingDeferredMessengerStarter implements DeferredMessengerDrainStarterInterface
{
    /**
     * @var list<array{command: list<string>, cwd: string, output_path: string, pid_path: string}>
     */
    public array $starts = [];

    public function start(array $command, string $cwd, string $outputPath, string $pidPath): bool
    {
        $this->starts[] = [
            'command' => $command,
            'cwd' => $cwd,
            'output_path' => $outputPath,
            'pid_path' => $pidPath,
        ];

        return true;
    }
}
