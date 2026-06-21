<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\ExtensionAssetRebuildDispatcher;
use App\Core\Extension\ExtensionDiscoveryDispatcher;
use App\Tests\Support\NullWorkflowResultMessageReporter;
use App\Tests\Support\RecordingMessageBus;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class ExtensionDispatcherTest extends TestCase
{
    public function testDiscoveryDispatchSkipsMessengerWhenStorageIsMissing(): void
    {
        $messageBus = new RecordingMessageBus();
        $dispatcher = new ExtensionDiscoveryDispatcher(
            $messageBus,
            new NullWorkflowResultMessageReporter(),
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
        );

        $result = $dispatcher->dispatch('cache_warmup');

        self::assertFalse($result->isSuccess());
        self::assertSame([], $messageBus->messages());
        self::assertSame('messenger_storage_unavailable', $result->issues()[0]->context()['reason']);
    }

    public function testDiscoveryDispatchRecognizesPrefixedMessengerStorage(): void
    {
        $messageBus = new RecordingMessageBus();
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE studio_messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        $previousServer = $_SERVER['APP_DATABASE_PREFIX'] ?? null;
        $previousEnv = $_ENV['APP_DATABASE_PREFIX'] ?? null;
        $_SERVER['APP_DATABASE_PREFIX'] = 'studio_';
        $_ENV['APP_DATABASE_PREFIX'] = 'studio_';

        try {
            $dispatcher = new ExtensionDiscoveryDispatcher(
                $messageBus,
                new NullWorkflowResultMessageReporter(),
                $connection,
            );

            $result = $dispatcher->dispatch('cache_warmup');
        } finally {
            if (null === $previousServer) {
                unset($_SERVER['APP_DATABASE_PREFIX']);
            } else {
                $_SERVER['APP_DATABASE_PREFIX'] = $previousServer;
            }

            if (null === $previousEnv) {
                unset($_ENV['APP_DATABASE_PREFIX']);
            } else {
                $_ENV['APP_DATABASE_PREFIX'] = $previousEnv;
            }
        }

        self::assertTrue($result->isSuccess());
        self::assertCount(1, $messageBus->messages());
    }

    public function testAssetRebuildDispatchSkipsMessengerWhenStorageIsMissing(): void
    {
        $messageBus = new RecordingMessageBus();
        $dispatcher = new ExtensionAssetRebuildDispatcher(
            $messageBus,
            new NullWorkflowResultMessageReporter(),
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
        );

        $result = $dispatcher->dispatch('test', 'cache_warmup');

        self::assertFalse($result->isSuccess());
        self::assertSame([], $messageBus->messages());
        self::assertSame('messenger_storage_unavailable', $result->issues()[0]->context()['reason']);
    }

    public function testAssetRebuildDispatchRecognizesPrefixedMessengerStorage(): void
    {
        $messageBus = new RecordingMessageBus();
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE studio_messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        $previousServer = $_SERVER['APP_DATABASE_PREFIX'] ?? null;
        $previousEnv = $_ENV['APP_DATABASE_PREFIX'] ?? null;
        $_SERVER['APP_DATABASE_PREFIX'] = 'studio_';
        $_ENV['APP_DATABASE_PREFIX'] = 'studio_';

        try {
            $dispatcher = new ExtensionAssetRebuildDispatcher(
                $messageBus,
                new NullWorkflowResultMessageReporter(),
                $connection,
            );

            $result = $dispatcher->dispatch('test', 'cache_warmup');
        } finally {
            if (null === $previousServer) {
                unset($_SERVER['APP_DATABASE_PREFIX']);
            } else {
                $_SERVER['APP_DATABASE_PREFIX'] = $previousServer;
            }

            if (null === $previousEnv) {
                unset($_ENV['APP_DATABASE_PREFIX']);
            } else {
                $_ENV['APP_DATABASE_PREFIX'] = $previousEnv;
            }
        }

        self::assertTrue($result->isSuccess());
        self::assertCount(1, $messageBus->messages());
    }
}
