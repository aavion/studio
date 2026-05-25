<?php

declare(strict_types=1);

namespace App\Content\Event;

use App\Content\Read\PublishedContentView;
use App\Core\Event\PublicEventInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\Event;

final class ContentRenderContextEvent extends Event implements PublicEventInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        private readonly PublishedContentView $contentView,
        private readonly Request $request,
        private array $context,
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

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function set(string $key, mixed $value): void
    {
        $this->context[$key] = $value;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function merge(array $values): void
    {
        $this->context = array_replace_recursive($this->context, $values);
    }
}
