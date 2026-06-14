<?php

declare(strict_types=1);

namespace App\Tests\View\Alert;

use App\View\Alert\UiAlert;
use App\View\Alert\UiAlertInbox;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class UiAlertInboxTest extends TestCase
{
    public function testItAppendsAndPollsQueuedAlertsWithoutRequiringInsertIds(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE ui_alert_inbox (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, topic VARCHAR(255) NOT NULL, payload CLOB NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL)');
        $inbox = new UiAlertInbox($connection);

        $result = $inbox->append(['topic.one', 'topic.two'], UiAlert::fromLevel('success', 'Queued'));

        self::assertSame(2, $result);
        self::assertSame([
            'cursor' => 1,
            'alerts' => [[
                'message' => 'Queued',
                'level' => 'success',
                'persistent' => false,
                'mode' => 'auto',
                'loading' => false,
            ]],
        ], $inbox->poll(['topic.one']));
    }

    public function testAppendReturnsNullForEmptyTopics(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $inbox = new UiAlertInbox($connection);

        self::assertNull($inbox->append([], UiAlert::fromLevel('info', 'Ignored')));
    }
}
