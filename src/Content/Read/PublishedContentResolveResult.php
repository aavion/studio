<?php

declare(strict_types=1);

namespace App\Content\Read;

use App\Core\Message\Message;

final readonly class PublishedContentResolveResult
{
    /**
     * @param list<Message> $messages
     */
    private function __construct(
        private PublishedContentResolveStatus $status,
        private ?PublishedContentView $view = null,
        private array $messages = [],
    ) {
    }

    /**
     * @param list<Message> $messages
     */
    public static function resolved(PublishedContentView $view, array $messages = []): self
    {
        return new self(PublishedContentResolveStatus::Resolved, $view, $messages);
    }

    public static function notFound(): self
    {
        return new self(PublishedContentResolveStatus::NotFound);
    }

    public static function notPublished(): self
    {
        return new self(PublishedContentResolveStatus::NotPublished);
    }

    public static function notPublic(): self
    {
        return new self(PublishedContentResolveStatus::NotPublic);
    }

    public static function contextUnavailable(): self
    {
        return new self(PublishedContentResolveStatus::ContextUnavailable);
    }

    public static function denied(): self
    {
        return new self(PublishedContentResolveStatus::Denied);
    }

    public function status(): PublishedContentResolveStatus
    {
        return $this->status;
    }

    public function view(): ?PublishedContentView
    {
        return $this->view;
    }

    /**
     * @return list<Message>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    public function isResolved(): bool
    {
        return PublishedContentResolveStatus::Resolved === $this->status;
    }

    public function isForbidden(): bool
    {
        return PublishedContentResolveStatus::NotPublic === $this->status;
    }

    public function isUnauthorized(): bool
    {
        return PublishedContentResolveStatus::Denied === $this->status;
    }
}
