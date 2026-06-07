<?php

declare(strict_types=1);

namespace App\Api\Http;

use JsonException;
use Symfony\Component\HttpFoundation\Request;

final readonly class ApiJsonRequestParser
{
    /**
     * @return array<string, mixed>
     */
    public function object(Request $request): array
    {
        $content = trim($request->getContent());
        if ('' === $content) {
            return [];
        }

        $payload = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($payload) || array_is_list($payload)) {
            throw new JsonException('API request body must be a JSON object.');
        }

        return $payload;
    }
}
