<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Event\EventHookDescriptor;
use App\Core\Event\PublicEventInterface;
use App\Entity\Extension;

final readonly class ExtensionEventListenerRegistration
{
    public function __construct(
        private Extension $extension,
        private ExtensionEventListenerContribution $contribution,
        private int $sequence,
    ) {
    }

    public function extension(): Extension
    {
        return $this->extension;
    }

    public function extensionName(): string
    {
        return $this->extension->extensionName();
    }

    /**
     * @return class-string<PublicEventInterface>
     */
    public function eventClass(): string
    {
        return $this->contribution->eventClass();
    }

    public function priority(): int
    {
        return $this->contribution->priority();
    }

    public function sequence(): int
    {
        return $this->sequence;
    }

    public function notify(PublicEventInterface $event, EventHookDescriptor $hook): void
    {
        ($this->contribution->listener())($event, new ExtensionEventContext($this->extension, $hook));
    }
}
