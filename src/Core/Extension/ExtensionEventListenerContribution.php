<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Event\PublicEventInterface;
use Closure;

final readonly class ExtensionEventListenerContribution
{
    private Closure $listener;

    /**
     * @param class-string<PublicEventInterface> $eventClass
     */
    public function __construct(
        private string $eventClass,
        callable $listener,
        private int $priority = 0,
    ) {
        $this->listener = Closure::fromCallable($listener);
    }

    /**
     * @return class-string<PublicEventInterface>
     */
    public function eventClass(): string
    {
        return $this->eventClass;
    }

    public function listener(): Closure
    {
        return $this->listener;
    }

    public function priority(): int
    {
        return $this->priority;
    }
}
