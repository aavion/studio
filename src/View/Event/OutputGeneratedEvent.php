<?php

declare(strict_types=1);

namespace App\View\Event;

use App\Core\Event\PublicEventInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\Event;

final class OutputGeneratedEvent extends Event implements PublicEventInterface
{
    public function __construct(
        private readonly Request $request,
        private string $content,
        private readonly int $statusCode,
        private readonly ?string $contentType,
    ) {
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

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function contentType(): ?string
    {
        return $this->contentType;
    }
}
