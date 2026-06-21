<?php

declare(strict_types=1);

namespace App\Tests\Core\Event;

use App\Core\Event\PublicEventDispatcher;
use App\Core\Event\PublicEventHookRegistry;
use App\Core\Event\PublicHookFailedEvent;
use App\Core\Extension\ExtensionEventContext;
use App\Core\Extension\ExtensionEventListenerContribution;
use App\Core\Extension\ExtensionEventListenerDispatcher;
use App\Core\Extension\ExtensionRuntimeContributionRegistry;
use App\Core\Extension\ExtensionScope;
use App\Core\Extension\ExtensionStatus;
use App\Entity\Extension;
use App\Tests\Support\NullWorkflowResultMessageReporter;
use App\View\ViewContextEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class PublicEventDispatcherTest extends TestCase
{
    public function testItDispatchesRegisteredPublicHooks(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ViewContextEvent::class, static function (ViewContextEvent $event): void {
            $event->set('handled', true);
        });

        $event = new ViewContextEvent([]);
        $result = (new PublicEventDispatcher($dispatcher, new PublicEventHookRegistry(), new NullWorkflowResultMessageReporter()))->dispatch($event);

        self::assertTrue($result->isSuccess());
        self::assertSame(['handled' => true], $event->context());
    }

    public function testItDispatchesExtensionListenersAfterNativePublicHookListeners(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ViewContextEvent::class, static function (ViewContextEvent $event): void {
            $event->set('order', ['native']);
        });
        $registry = new ExtensionRuntimeContributionRegistry();
        $registry->add($this->extension(), new ExtensionEventListenerContribution(
            ViewContextEvent::class,
            static function (ViewContextEvent $event, ExtensionEventContext $context): void {
                $order = $event->context()['order'] ?? [];
                $order[] = $context->extensionName();
                $event->set('order', $order);
            },
        ));

        $event = new ViewContextEvent([]);
        $result = (new PublicEventDispatcher(
            $dispatcher,
            new PublicEventHookRegistry(),
            new NullWorkflowResultMessageReporter(),
            extensionListenerDispatcher: new ExtensionEventListenerDispatcher($registry),
        ))->dispatch($event);

        self::assertTrue($result->isSuccess());
        self::assertSame(['native', 'demo-module'], $event->context()['order']);
    }

    public function testItReturnsStructuredIssueForListenerFailures(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ViewContextEvent::class, static function (): void {
            throw new \RuntimeException('Nope');
        });
        $reported = null;
        $dispatcher->addListener(PublicHookFailedEvent::class, static function (PublicHookFailedEvent $event) use (&$reported): void {
            $reported = $event;
        });

        $event = new ViewContextEvent([]);
        $result = (new PublicEventDispatcher($dispatcher, new PublicEventHookRegistry(), new NullWorkflowResultMessageReporter()))->dispatch($event, [
            'operation' => 'test',
        ], 'demo-extension');

        self::assertFalse($result->isSuccess());
        self::assertSame('event.hook_listener_failed', $result->firstIssue()?->code());
        self::assertSame(ViewContextEvent::class, $result->firstIssue()?->context()['event']);
        self::assertSame(\RuntimeException::class, $result->firstIssue()?->context()['exception']);
        self::assertInstanceOf(PublicHookFailedEvent::class, $reported);
        self::assertSame($event, $reported->hookEvent());
        self::assertSame(ViewContextEvent::class, $reported->hook()->eventClass());
        self::assertSame('test', $reported->context()['operation']);
        self::assertSame('demo-extension', $reported->extension());
    }

    public function testItReportsExtensionListenerFailuresWithExtensionOwnership(): void
    {
        $dispatcher = new EventDispatcher();
        $reported = null;
        $dispatcher->addListener(PublicHookFailedEvent::class, static function (PublicHookFailedEvent $event) use (&$reported): void {
            $reported = $event;
        });
        $registry = new ExtensionRuntimeContributionRegistry();
        $registry->add($this->extension(), new ExtensionEventListenerContribution(
            ViewContextEvent::class,
            static function (): void {
                throw new \RuntimeException('Extension listener failed');
            },
        ));

        $result = (new PublicEventDispatcher(
            $dispatcher,
            new PublicEventHookRegistry(),
            new NullWorkflowResultMessageReporter(),
            extensionListenerDispatcher: new ExtensionEventListenerDispatcher($registry),
        ))->dispatch(new ViewContextEvent([]), ['operation' => 'extension-listener-test']);

        self::assertFalse($result->isSuccess());
        self::assertSame('event.hook_listener_failed', $result->firstIssue()?->code());
        self::assertSame('demo-module', $result->firstIssue()?->context()['extension']);
        self::assertSame('extension', $result->firstIssue()?->context()['listener']);
        self::assertInstanceOf(PublicHookFailedEvent::class, $reported);
        self::assertNull($reported->extension());
        self::assertSame('demo-module', $reported->context()['extension_listener']);
        self::assertSame('extension-listener-test', $reported->context()['operation']);
    }

    public function testFailureReportListenerCannotHideOriginalHookFailure(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ViewContextEvent::class, static function (): void {
            throw new \RuntimeException('Original failure');
        });
        $dispatcher->addListener(PublicHookFailedEvent::class, static function (): void {
            throw new \RuntimeException('Reporter failure');
        });

        $event = new ViewContextEvent([]);
        $result = (new PublicEventDispatcher($dispatcher, new PublicEventHookRegistry(), new NullWorkflowResultMessageReporter()))->dispatch($event);

        self::assertFalse($result->isSuccess());
        self::assertSame('Original failure', $result->firstIssue()?->context()['message']);
    }

    private function extension(): Extension
    {
        return new Extension(
            '10000000-0000-7000-8000-000000000703',
            [ExtensionScope::Module],
            'demo-module',
            'extensions/demo-module',
            ExtensionStatus::Active,
        );
    }
}
