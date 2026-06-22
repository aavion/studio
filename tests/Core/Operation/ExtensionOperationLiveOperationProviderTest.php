<?php

declare(strict_types=1);

namespace App\Tests\Core\Operation;

use App\Core\Extension\ExtensionActionQueueProviderInterface;
use App\Core\Extension\ExtensionOperationDefinition;
use App\Core\Extension\ExtensionRuntimeContributionRegistry;
use App\Core\Extension\ExtensionScope;
use App\Core\Extension\ExtensionStatus;
use App\Core\Operation\ActionQueue;
use App\Core\Operation\Live\ExtensionOperationLiveOperationProvider;
use App\Core\Operation\Live\LiveOperationQueueFactory;
use App\Entity\Extension;
use PHPUnit\Framework\TestCase;

final class ExtensionOperationLiveOperationProviderTest extends TestCase
{
    public function testItCreatesQueuesForRegisteredExtensionOperationTargets(): void
    {
        $registry = new ExtensionRuntimeContributionRegistry();
        $registry->add($this->extension(), [
            new ExtensionOperationDefinition(
                'demo-module.cleanup',
                'ext.demo-module.cleanup.label',
                'ext.demo-module.cleanup.description',
            ),
            new class implements ExtensionActionQueueProviderInterface {
                public function extensionActionQueue(string $target, array $payload = []): ?ActionQueue
                {
                    return 'demo-module.cleanup' === $target
                        ? ActionQueue::create('cleanup', context: ['received' => $payload])
                        : null;
                }
            },
        ]);
        $provider = new ExtensionOperationLiveOperationProvider($registry);

        $result = $provider->create([
            'extension' => 'demo-module',
            'target' => 'demo-module.cleanup',
            'mode' => 'fast',
            'object' => new \stdClass(),
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame(LiveOperationQueueFactory::EXTENSION_OPERATION, $provider->operation());
        self::assertSame('cleanup', $result->value()?->name());
        self::assertSame('demo-module', $result->value()?->context()['extension']);
        self::assertSame('demo-module.cleanup', $result->value()?->context()['target']);
        self::assertSame('fast', $result->value()?->context()['received']['mode']);
        self::assertArrayNotHasKey('object', $result->value()?->context()['received']);
    }

    public function testItRejectsUnregisteredOrForeignTargets(): void
    {
        $registry = new ExtensionRuntimeContributionRegistry();
        $registry->add($this->extension(), new ExtensionOperationDefinition(
            'demo-module.cleanup',
            'ext.demo-module.cleanup.label',
            'ext.demo-module.cleanup.description',
        ));
        $provider = new ExtensionOperationLiveOperationProvider($registry);

        $unknown = $provider->create(['extension' => 'demo-module', 'target' => 'demo-module.missing']);
        $foreign = $provider->create(['extension' => 'demo-module', 'target' => 'other-module.cleanup']);

        self::assertFalse($unknown->isSuccess());
        self::assertSame('unregistered_target', $unknown->context()['reason']);
        self::assertFalse($foreign->isSuccess());
        self::assertSame('invalid_target', $foreign->context()['reason']);
    }

    private function extension(): Extension
    {
        return new Extension(
            '10000000-0000-7000-8000-000000000801',
            [ExtensionScope::Module, ExtensionScope::Operations],
            'demo-module',
            'extensions/demo-module',
            ExtensionStatus::Active,
        );
    }
}
