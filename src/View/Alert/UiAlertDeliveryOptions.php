<?php

declare(strict_types=1);

namespace App\View\Alert;

final readonly class UiAlertDeliveryOptions
{
    public function __construct(
        private UiAlertDelivery $delivery = UiAlertDelivery::Queue,
        private bool $private = false,
        private ?int $ttlSeconds = 86400,
        private ?string $locale = null,
    ) {
    }

    public static function queued(?string $locale = null): self
    {
        return new self(UiAlertDelivery::Queue, locale: $locale);
    }

    public static function direct(?string $locale = null): self
    {
        return new self(UiAlertDelivery::Direct, ttlSeconds: null, locale: $locale);
    }

    public static function push(?string $locale = null): self
    {
        return new self(UiAlertDelivery::Push, ttlSeconds: null, locale: $locale);
    }

    public function delivery(): UiAlertDelivery
    {
        return $this->delivery;
    }

    public function queues(): bool
    {
        return UiAlertDelivery::Queue === $this->delivery;
    }

    public function pushes(): bool
    {
        return UiAlertDelivery::Queue === $this->delivery || UiAlertDelivery::Push === $this->delivery;
    }

    public function flashes(): bool
    {
        return UiAlertDelivery::Direct === $this->delivery;
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
