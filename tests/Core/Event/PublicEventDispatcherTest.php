<?php

declare(strict_types=1);

namespace App\Tests\Core\Event;

use App\Core\Event\PublicEventDispatcher;
use App\Core\Event\PublicEventHookRegistry;
use App\Core\Event\PublicHookFailedEvent;
use App\View\ViewContextEvent;
use App\Tests\Support\NullWorkflowResultMessageReporter;
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
        ], 'demo-package');

        self::assertFalse($result->isSuccess());
        self::assertSame('event.hook_listener_failed', $result->firstIssue()?->code());
        self::assertSame(ViewContextEvent::class, $result->firstIssue()?->context()['event']);
        self::assertSame(\RuntimeException::class, $result->firstIssue()?->context()['exception']);
        self::assertInstanceOf(PublicHookFailedEvent::class, $reported);
        self::assertSame($event, $reported->hookEvent());
        self::assertSame(ViewContextEvent::class, $reported->hook()->eventClass());
        self::assertSame('test', $reported->context()['operation']);
        self::assertSame('demo-package', $reported->package());
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
}
