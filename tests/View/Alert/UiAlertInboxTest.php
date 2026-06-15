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
        $connection->executeStatement('CREATE TABLE ui_alert_inbox (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, topic VARCHAR(80) NOT NULL, payload CLOB NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL)');
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
            'has_more' => false,
        ], $inbox->poll(['topic.one']));
    }

    public function testItReportsWhenAnotherPollPageIsAvailable(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE ui_alert_inbox (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, topic VARCHAR(80) NOT NULL, payload CLOB NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL)');
        $inbox = new UiAlertInbox($connection);

        self::assertSame(1, $inbox->append(['topic.one'], UiAlert::fromLevel('success', 'First')));
        self::assertSame(1, $inbox->append(['topic.one'], UiAlert::fromLevel('success', 'Second')));

        $firstPage = $inbox->poll(['topic.one'], limit: 1);
        $secondPage = $inbox->poll(['topic.one'], $firstPage['cursor'], limit: 1);

        self::assertSame(['First'], array_column($firstPage['alerts'], 'message'));
        self::assertTrue($firstPage['has_more']);
        self::assertSame(['Second'], array_column($secondPage['alerts'], 'message'));
        self::assertFalse($secondPage['has_more']);
    }

    public function testItStoresBoundedTopicKeysForLongPublicTopics(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE ui_alert_inbox (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, topic VARCHAR(80) NOT NULL, payload CLOB NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL)');
        $inbox = new UiAlertInbox($connection);
        $topic = 'urn:system:ui-alerts:user:'.str_repeat('a', 64);

        self::assertSame(1, $inbox->append([$topic], UiAlert::fromLevel('success', 'Queued')));

        $storedTopic = (string) $connection->fetchOne('SELECT topic FROM ui_alert_inbox');
        self::assertSame(71, strlen($storedTopic));
        self::assertStringStartsWith('sha256:', $storedTopic);
        self::assertSame('Queued', $inbox->poll([$topic])['alerts'][0]['message'] ?? null);
    }

    public function testAppendReturnsNullForEmptyTopics(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $inbox = new UiAlertInbox($connection);

        self::assertNull($inbox->append([], UiAlert::fromLevel('info', 'Ignored')));
    }
}
