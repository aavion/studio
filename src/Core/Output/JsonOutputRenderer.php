<?php

declare(strict_types=1);

namespace App\Core\Output;

use Symfony\Component\HttpFoundation\Response;

final readonly class JsonOutputRenderer
{
    /**
     * @param array<string, string> $headers
     *
     * @throws \JsonException
     */
    public function render(mixed $payload, int $status = Response::HTTP_OK, array $headers = []): Response
    {
        return $this->renderRaw(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $status,
            $headers,
        );
    }

    /**
     * @param array<string, string> $headers
     *
     * @throws \JsonException
     */
    public function renderRaw(string $json, int $status = Response::HTTP_OK, array $headers = []): Response
    {
        json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return new Response($json, $status, array_replace([
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], $headers));
    }
}
