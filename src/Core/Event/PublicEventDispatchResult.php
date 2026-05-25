<?php

declare(strict_types=1);

namespace App\Core\Event;

use App\Core\Workflow\OperationIssue;

final readonly class PublicEventDispatchResult
{
    /**
     * @param list<OperationIssue> $issues
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
     * @param list<OperationIssue> $issues
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
     * @return list<OperationIssue>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    public function firstIssue(): ?OperationIssue
    {
        return $this->issues[0] ?? null;
    }
}
