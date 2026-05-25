<?php

declare(strict_types=1);

namespace App\Content\Event;

use App\Content\Read\PublishedContentView;
use App\Core\Event\PublicEventInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\Event;

final class ContentRenderedEvent extends Event implements PublicEventInterface
{
    public function __construct(
        private readonly PublishedContentView $contentView,
        private readonly Request $request,
        private string $content,
    ) {
    }

    public function contentView(): PublishedContentView
    {
        return $this->contentView;
    }

    public function request(): Request
    {
        return $this->request;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    public function appendContent(string $content): void
    {
        $this->content .= $content;
    }

    public function prependContent(string $content): void
    {
        $this->content = $content.$this->content;
    }
}
