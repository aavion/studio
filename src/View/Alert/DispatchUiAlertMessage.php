<?php

declare(strict_types=1);

namespace App\View\Alert;

final readonly class DispatchUiAlertMessage
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $topic,
        public array $payload,
        public UiAlertDelivery $delivery = UiAlertDelivery::Queue,
        public bool $private = false,
        public ?int $ttlSeconds = 86400,
        public ?string $locale = null,
    ) {
    }
}
