<?php

declare(strict_types=1);

namespace App\Backend;

use App\Core\Message\Message;
use Symfony\Component\HttpFoundation\Response;

final readonly class BackendRouteResult
{
    private function __construct(
        private BackendArea $area,
        private int $statusCode,
        private ?string $template,
        private ?BackendViewDefinition $view = null,
        private ?Message $message = null,
    ) {
    }

    public static function index(BackendArea $area): self
    {
        return new self($area, Response::HTTP_OK, $area->indexTemplate());
    }

    public static function fromView(BackendViewDefinition $view): self
    {
        return new self($view->area(), Response::HTTP_OK, $view->template(), $view);
    }

    public static function withMessage(BackendArea $area, int $statusCode, Message $message): self
    {
        return new self($area, $statusCode, $area->messageTemplate(), message: $message);
    }

    public function area(): BackendArea
    {
        return $this->area;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function template(): string
    {
        return $this->template ?? $this->area->messageTemplate();
    }

    public function view(): ?BackendViewDefinition
    {
        return $this->view;
    }

    public function message(): ?Message
    {
        return $this->message;
    }
}
