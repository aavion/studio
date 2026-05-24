<?php

declare(strict_types=1);

namespace App\Content\Read;

final readonly class PublishedContentResolveResult
{
    private function __construct(
        private PublishedContentResolveStatus $status,
        private ?PublishedContentView $view = null,
    ) {
    }

    public static function resolved(PublishedContentView $view): self
    {
        return new self(PublishedContentResolveStatus::Resolved, $view);
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

    public function isResolved(): bool
    {
        return PublishedContentResolveStatus::Resolved === $this->status;
    }

    public function isForbidden(): bool
    {
        return in_array($this->status, [
            PublishedContentResolveStatus::NotPublic,
            PublishedContentResolveStatus::Denied,
        ], true);
    }
}
