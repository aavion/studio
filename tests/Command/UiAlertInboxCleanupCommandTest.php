<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\UiAlertInboxCleanupCommand;
use App\View\Alert\UiAlertInbox;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class UiAlertInboxCleanupCommandTest extends TestCase
{
    public function testItReportsRemovedExpiredRows(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE ui_alert_inbox (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, topic VARCHAR(80) NOT NULL, payload CLOB NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL)');
        $connection->insert('ui_alert_inbox', [
            'topic' => 'test.expired',
            'payload' => '{}',
            'created_at' => '2026-06-14 00:00:00',
            'expires_at' => '2026-06-14 00:00:00',
        ]);
        $connection->insert('ui_alert_inbox', [
            'topic' => 'test.active',
            'payload' => '{}',
            'created_at' => '2026-06-14 00:00:00',
            'expires_at' => '2999-01-01 00:00:00',
        ]);

        $tester = new CommandTester(new UiAlertInboxCleanupCommand(new UiAlertInbox($connection)));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('UI alert inbox cleanup removed 1 expired row(s).', $tester->getDisplay());
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM ui_alert_inbox'));
    }

    public function testItFailsWhenCleanupQueryFails(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $tester = new CommandTester(new UiAlertInboxCleanupCommand(new UiAlertInbox($connection)));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('UI alert inbox cleanup failed:', $tester->getDisplay());
    }
}
