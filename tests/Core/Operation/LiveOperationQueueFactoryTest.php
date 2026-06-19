<?php

declare(strict_types=1);

namespace App\Tests\Core\Operation;

use App\Core\Operation\Live\LiveOperationQueueFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LiveOperationQueueFactoryTest extends KernelTestCase
{
    public function testItCreatesSupportedQueues(): void
    {
        self::bootKernel();
        $factory = $this->factory();

        $backendCacheClear = $factory->create(LiveOperationQueueFactory::BACKEND_CACHE_CLEAR, [
            'environment' => 'test',
            'trigger' => 'admin_ui',
        ]);
        $extensionDiscovery = $factory->create(LiveOperationQueueFactory::EXTENSION_DISCOVERY, [
            'environment' => 'test',
            'trigger' => 'admin_ui',
        ]);
        $extensionLifecycle = $factory->create(LiveOperationQueueFactory::EXTENSION_LIFECYCLE, [
            'extension' => 'demo-module',
            'action' => 'activate',
            'environment' => 'test',
            'trigger' => 'admin_ui',
        ]);
        $aclGroupApply = $factory->create(LiveOperationQueueFactory::ACL_GROUP_APPLY, [
            'group_uid' => 'aaaaaaaa-aaaa-7aaa-aaaa-aaaaaaaaaaaa',
            'action' => 'delete',
            'actor_uid' => 'bbbbbbbb-bbbb-7bbb-bbbb-bbbbbbbbbbbb',
            'environment' => 'test',
            'trigger' => 'admin_ui',
        ]);
        $setupApply = $factory->create(LiveOperationQueueFactory::SETUP_APPLY, [
            'values' => [
                'language' => 'en',
                'admin_username' => 'admin',
                'admin_password' => 'VerySecure1!',
                'admin_password_confirm' => 'VerySecure1!',
                'admin_email' => 'admin@example.test',
            ],
            'trigger' => 'setup_wizard',
        ]);
        $extensionInstallVerify = $factory->create(LiveOperationQueueFactory::EXTENSION_INSTALL_VERIFY, [
            'install_id' => 'aaaaaaaaaaaaaaaaaaaaaaaa',
            'trigger' => 'admin_ui',
        ]);
        $extensionInstallApply = $factory->create(LiveOperationQueueFactory::EXTENSION_INSTALL_APPLY, [
            'install_id' => 'aaaaaaaaaaaaaaaaaaaaaaaa',
            'extension' => 'demo-module',
            'trigger' => 'admin_ui',
        ]);
        $geoIpUpdate = $factory->create(LiveOperationQueueFactory::GEOIP_DATABASE_UPDATE, [
            'environment' => 'test',
            'trigger' => 'admin_ui',
        ]);

        self::assertTrue($backendCacheClear->isSuccess());
        self::assertSame('backend cache clear', $backendCacheClear->value()?->name());
        self::assertSame('test', $backendCacheClear->value()?->context()['environment']);
        self::assertTrue($extensionDiscovery->isSuccess());
        self::assertSame('extension discovery', $extensionDiscovery->value()?->name());
        self::assertSame('admin_ui', $extensionDiscovery->value()?->context()['trigger']);
        self::assertTrue($extensionLifecycle->isSuccess());
        self::assertSame('activate', $extensionLifecycle->value()?->context()['action']);
        self::assertTrue($aclGroupApply->isSuccess());
        self::assertSame('Delete ACL group and clean references', $aclGroupApply->value()?->actions()[0]->label());
        self::assertTrue($setupApply->isSuccess());
        self::assertSame('select_language', $setupApply->value()?->actions()[0]->label());
        self::assertTrue($extensionInstallVerify->isSuccess());
        self::assertSame('extension install verification', $extensionInstallVerify->value()?->name());
        self::assertTrue($extensionInstallApply->isSuccess());
        self::assertSame('demo-module', $extensionInstallApply->value()?->context()['extension']);
        self::assertTrue($geoIpUpdate->isSuccess());
        self::assertSame('geoip database update', $geoIpUpdate->value()?->name());
        self::assertSame('admin_ui', $geoIpUpdate->value()?->context()['trigger']);
    }

    public function testItRejectsUnknownOperationsAndInvalidPayloads(): void
    {
        self::bootKernel();
        $factory = $this->factory();

        $unknown = $factory->create('missing.operation');
        $invalidLifecycle = $factory->create(LiveOperationQueueFactory::EXTENSION_LIFECYCLE, [
            'extension' => 'demo-module',
        ]);
        $invalidAclGroupApply = $factory->create(LiveOperationQueueFactory::ACL_GROUP_APPLY, [
            'group_uid' => 'aaaaaaaa-aaaa-7aaa-aaaa-aaaaaaaaaaaa',
        ]);

        self::assertFalse($unknown->isSuccess());
        self::assertSame('message.operation.unknown', $unknown->firstIssue()?->translationKey());
        self::assertFalse($invalidLifecycle->isSuccess());
        self::assertSame('message.operation.invalid_payload', $invalidLifecycle->firstIssue()?->translationKey());
        self::assertFalse($invalidAclGroupApply->isSuccess());
        self::assertSame('message.operation.invalid_payload', $invalidAclGroupApply->firstIssue()?->translationKey());
    }

    private function factory(): LiveOperationQueueFactory
    {
        $factory = self::getContainer()->get(LiveOperationQueueFactory::class);
        self::assertInstanceOf(LiveOperationQueueFactory::class, $factory);

        return $factory;
    }
}
