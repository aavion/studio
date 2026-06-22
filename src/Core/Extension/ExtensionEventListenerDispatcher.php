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
        $failures = $this->dispatchCollectingFailures($event, $hook);
        if ([] !== $failures) {
            throw $failures[0];
        }
    }

    /**
     * @return list<ExtensionEventListenerFailedException>
     */
    public function dispatchCollectingFailures(PublicEventInterface $event, EventHookDescriptor $hook): array
    {
        $failures = [];
        foreach ($this->runtimeContributions->extensionEventListeners($event::class) as $registration) {
            try {
                $registration->notify($event, $hook);
            } catch (Throwable $error) {
                $failures[] = new ExtensionEventListenerFailedException($registration, $error);
            }
        }

        return $failures;
    }
}
