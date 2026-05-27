<?php

declare(strict_types=1);

namespace App\Tests\Core\Operation;

use App\Core\Operation\Live\LiveOperationQueueFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LiveOperationQueueFactoryTest extends KernelTestCase
{
    public function testItCreatesBackendCacheClearQueue(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(LiveOperationQueueFactory::class);

        self::assertInstanceOf(LiveOperationQueueFactory::class, $factory);
        $result = $factory->create(LiveOperationQueueFactory::BACKEND_CACHE_CLEAR, [
            'environment' => 'test',
            'trigger' => 'admin_ui',
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame('backend cache clear', $result->value()?->name());
        self::assertSame('test', $result->value()?->context()['environment']);
        self::assertCount(1, $result->value()?->actions());
    }

    public function testItRejectsUnknownOperations(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(LiveOperationQueueFactory::class);

        $result = $factory->create('missing.operation');

        self::assertFalse($result->isSuccess());
        self::assertSame('message.operation.unknown', $result->firstIssue()?->translationKey());
    }

    public function testItCreatesPackageDiscoveryQueue(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(LiveOperationQueueFactory::class);

        $result = $factory->create(LiveOperationQueueFactory::PACKAGE_DISCOVERY, [
            'environment' => 'test',
            'trigger' => 'admin_ui',
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame('package discovery', $result->value()?->name());
        self::assertSame('test', $result->value()?->context()['environment']);
        self::assertSame('admin_ui', $result->value()?->context()['trigger']);
        self::assertCount(1, $result->value()?->actions());
    }


    public function testItCreatesPackageLifecycleQueue(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(LiveOperationQueueFactory::class);

        $result = $factory->create(LiveOperationQueueFactory::PACKAGE_LIFECYCLE, [
            'package' => 'demo-module',
            'action' => 'activate',
            'environment' => 'test',
            'trigger' => 'admin_ui',
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame('package lifecycle', $result->value()?->name());
        self::assertSame('demo-module', $result->value()?->context()['package']);
        self::assertSame('activate', $result->value()?->context()['action']);
        self::assertCount(1, $result->value()?->actions());
    }

    public function testItRejectsInvalidPackageLifecyclePayload(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(LiveOperationQueueFactory::class);

        $result = $factory->create(LiveOperationQueueFactory::PACKAGE_LIFECYCLE, [
            'package' => 'demo-module',
        ]);

        self::assertFalse($result->isSuccess());
        self::assertSame('message.operation.invalid_payload', $result->firstIssue()?->translationKey());
    }
}
