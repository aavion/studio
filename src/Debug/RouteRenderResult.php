<?php

declare(strict_types=1);

namespace App\Debug;

final readonly class RouteRenderResult
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public int $statusCode,
        public string $content,
        public array $headers,
    ) {
    }
}
