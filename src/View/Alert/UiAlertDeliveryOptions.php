<?php

declare(strict_types=1);

namespace App\View\Alert;

final readonly class UiAlertDeliveryOptions
{
    public function __construct(
        private bool $queue = true,
        private bool $push = true,
        private bool $private = true,
        private ?int $ttlSeconds = 86400,
        private ?string $locale = null,
    ) {
    }

    public static function queued(?string $locale = null): self
    {
        return new self(locale: $locale);
    }

    public static function direct(?string $locale = null): self
    {
        return new self(queue: false, locale: $locale);
    }

    public function queue(): bool
    {
        return $this->queue;
    }

    public function push(): bool
    {
        return $this->push;
    }

    public function private(): bool
    {
        return $this->private;
    }

    public function ttlSeconds(): ?int
    {
        return $this->ttlSeconds;
    }

    public function locale(): ?string
    {
        return $this->locale;
    }
}
