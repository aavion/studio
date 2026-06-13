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
        public bool $queue = true,
        public bool $push = true,
        public bool $private = true,
        public ?int $ttlSeconds = 86400,
        public ?string $locale = null,
    ) {
    }
}
