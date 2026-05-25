<?php

declare(strict_types=1);

namespace App\Core\Event;

use App\Core\Message\Message;

final readonly class PublicEventDispatchResult
{
    /**
     * @param list<Message> $issues
     */
    private function __construct(
        private PublicEventInterface $event,
        private array $issues = [],
    ) {
    }

    public static function success(PublicEventInterface $event): self
    {
        return new self($event);
    }

    /**
     * @param list<Message> $issues
     */
    public static function failed(PublicEventInterface $event, array $issues): self
    {
        return new self($event, $issues);
    }

    public function event(): PublicEventInterface
    {
        return $this->event;
    }

    public function isSuccess(): bool
    {
        return [] === $this->issues;
    }

    /**
     * @return list<Message>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    public function firstIssue(): ?Message
    {
        return $this->issues[0] ?? null;
    }
}
