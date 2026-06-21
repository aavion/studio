<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Event\EventHookDescriptor;
use App\Core\Event\PublicEventInterface;
use Throwable;

final readonly class ExtensionEventListenerDispatcher
{
    public function __construct(private ExtensionRuntimeContributionRegistry $runtimeContributions)
    {
    }

    public function dispatch(PublicEventInterface $event, EventHookDescriptor $hook): void
    {
        foreach ($this->runtimeContributions->extensionEventListeners($event::class) as $registration) {
            try {
                $registration->notify($event, $hook);
            } catch (Throwable $error) {
                throw new ExtensionEventListenerFailedException($registration, $error);
            }
        }
    }
}
