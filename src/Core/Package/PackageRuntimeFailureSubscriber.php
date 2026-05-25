<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Event\PublicHookFailedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class PackageRuntimeFailureSubscriber implements EventSubscriberInterface
{
    public function __construct(private PackageRuntimeFailureHandler $handler)
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
