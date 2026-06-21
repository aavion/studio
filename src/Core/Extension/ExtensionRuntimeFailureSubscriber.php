<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Event\PublicHookFailedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class ExtensionRuntimeFailureSubscriber implements EventSubscriberInterface
{
    public function __construct(private ExtensionRuntimeFailureHandler $handler)
    {
    }

    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            PublicHookFailedEvent::class => 'onPublicHookFailed',
        ];
    }

    public function onPublicHookFailed(PublicHookFailedEvent $event): void
    {
        $this->handler->handleHookFailure($event);
    }
}
