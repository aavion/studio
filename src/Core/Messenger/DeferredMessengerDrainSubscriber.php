<?php

declare(strict_types=1);

namespace App\Core\Messenger;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class DeferredMessengerDrainSubscriber implements EventSubscriberInterface
{
    public function __construct(private DeferredMessengerDrain $drain)
    {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => ['onKernelTerminate', -1024],
        ];
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ('scheduler_cron_run' === $request->attributes->get('_route') || str_starts_with($request->getPathInfo(), '/cron/run')) {
            return;
        }

        $this->drain->drainPendingMessages();
    }
}
