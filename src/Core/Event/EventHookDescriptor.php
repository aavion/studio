<?php

declare(strict_types=1);

namespace App\Core\Event;

use App\Core\Event\EventMessageCode;
use App\Core\Event\EventMessageKey;
use App\Core\Message\MessageException;

final readonly class EventHookDescriptor
{
    /**
     * @param class-string<PublicEventInterface> $eventClass
     */
    public function __construct(
        private string $eventClass,
        private string $domain,
        private EventHookMode $mode,
        private string $summaryKey,
        private bool $mutable = false,
        private bool $stoppable = false,
    ) {
        if (!is_a($eventClass, PublicEventInterface::class, true)) {
            throw $this->invalidDefinition($eventClass, 'event_class');
        }

        if ('' === trim($domain)) {
            throw $this->invalidDefinition($eventClass, 'domain');
        }

        if ('' === trim($summaryKey)) {
            throw $this->invalidDefinition($eventClass, 'summary');
        }
    }

    /**
     * @return class-string<PublicEventInterface>
     */
    public function eventClass(): string
    {
        return $this->eventClass;
    }

    public function domain(): string
    {
        return $this->domain;
    }

    public function mode(): EventHookMode
    {
        return $this->mode;
    }

    public function summary(): string
    {
        return $this->summaryKey;
    }

    public function summaryKey(): string
    {
        return $this->summaryKey;
    }

    public function mutable(): bool
    {
        return $this->mutable;
    }

    public function stoppable(): bool
    {
        return $this->stoppable;
    }

    /**
     * @return array{event: class-string<PublicEventInterface>, domain: string, mode: string, summary_key: string, mutable: bool, stoppable: bool}
     */
    public function toArray(): array
    {
        return [
            'event' => $this->eventClass,
            'domain' => $this->domain,
            'mode' => $this->mode->value,
            'summary_key' => $this->summaryKey,
            'mutable' => $this->mutable,
            'stoppable' => $this->stoppable,
        ];
    }

    private function invalidDefinition(string $eventClass, string $field): MessageException
    {
        return MessageException::forMessage(EventMessageCode::EVENT_HOOK_INVALID, EventMessageKey::EVENT_HOOK_INVALID, [
            '%event%' => $eventClass,
        ], [
            'event' => $eventClass,
            'field' => $field,
        ]);
    }
}
