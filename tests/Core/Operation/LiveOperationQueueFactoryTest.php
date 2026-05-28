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

    public function testItCreatesAclGroupApplyQueue(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(LiveOperationQueueFactory::class);

        $result = $factory->create(LiveOperationQueueFactory::ACL_GROUP_APPLY, [
            'group_uid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'action' => 'delete',
            'environment' => 'test',
            'trigger' => 'admin_ui',
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame('acl group apply', $result->value()?->name());
        self::assertSame('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', $result->value()?->context()['group_uid']);
        self::assertSame('delete', $result->value()?->context()['action']);
        self::assertCount(1, $result->value()?->actions());
        self::assertSame('Delete ACL group and clean references', $result->value()?->actions()[0]->label());
    }

    public function testItRejectsInvalidAclGroupApplyPayload(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(LiveOperationQueueFactory::class);

        $result = $factory->create(LiveOperationQueueFactory::ACL_GROUP_APPLY, [
            'group_uid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        ]);

        self::assertFalse($result->isSuccess());
        self::assertSame('message.operation.invalid_payload', $result->firstIssue()?->translationKey());
    }

    public function testItCreatesPackageInstallQueues(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(LiveOperationQueueFactory::class);

        $verify = $factory->create(LiveOperationQueueFactory::PACKAGE_INSTALL_VERIFY, [
            'install_id' => 'aaaaaaaaaaaaaaaaaaaaaaaa',
            'trigger' => 'admin_ui',
        ]);
        $apply = $factory->create(LiveOperationQueueFactory::PACKAGE_INSTALL_APPLY, [
            'install_id' => 'aaaaaaaaaaaaaaaaaaaaaaaa',
            'package' => 'demo-module',
            'trigger' => 'admin_ui',
        ]);

        self::assertTrue($verify->isSuccess());
        self::assertSame('package install verification', $verify->value()?->name());
        self::assertCount(1, $verify->value()?->actions());
        self::assertTrue($apply->isSuccess());
        self::assertSame('package install', $apply->value()?->name());
        self::assertSame('demo-module', $apply->value()?->context()['package']);
        self::assertCount(1, $apply->value()?->actions());
    }
}
