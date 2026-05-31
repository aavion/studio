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
}
