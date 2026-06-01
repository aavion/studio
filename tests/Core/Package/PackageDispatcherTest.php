<?php

declare(strict_types=1);

namespace App\Tests\Core\Package;

use App\Core\Package\PackageAssetRebuildDispatcher;
use App\Core\Package\PackageDiscoveryDispatcher;
use App\Tests\Support\NullWorkflowResultMessageReporter;
use App\Tests\Support\RecordingMessageBus;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class PackageDispatcherTest extends TestCase
{
    public function testDiscoveryDispatchSkipsMessengerWhenStorageIsMissing(): void
    {
        $messageBus = new RecordingMessageBus();
        $dispatcher = new PackageDiscoveryDispatcher(
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
            $dispatcher = new PackageDiscoveryDispatcher(
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
        $dispatcher = new PackageAssetRebuildDispatcher(
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
            $dispatcher = new PackageAssetRebuildDispatcher(
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
