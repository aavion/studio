<?php

declare(strict_types=1);

namespace App\Core\Event;

use App\Core\Message\Message;
use Symfony\Contracts\EventDispatcher\Event;
use Throwable;

final class PublicHookFailedEvent extends Event
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        private readonly PublicEventInterface $hookEvent,
        private readonly EventHookDescriptor $hook,
        private readonly Message $issue,
        private readonly Throwable $exception,
        private readonly array $context = [],
        private readonly ?string $package = null,
    ) {
    }

    public function hookEvent(): PublicEventInterface
    {
        return $this->hookEvent;
    }

    public function hook(): EventHookDescriptor
    {
        return $this->hook;
    }

    public function issue(): Message
    {
        return $this->issue;
    }

    public function exception(): Throwable
    {
        return $this->exception;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function package(): ?string
    {
        return $this->package;
    }
}
