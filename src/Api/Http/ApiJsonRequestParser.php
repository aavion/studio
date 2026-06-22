<?php

declare(strict_types=1);

namespace App\Api\Http;

use JsonException;
use Symfony\Component\HttpFoundation\Request;

final readonly class ApiJsonRequestParser
{
    public const REASON_INVALID_JSON = 'invalid_json';
    public const REASON_EXPECTED_OBJECT = 'expected_object';

    /**
     * @return array<string, mixed>
     */
    public function object(Request $request): array
    {
        $content = trim($request->getContent());
        if ('' === $content) {
            return [];
        }

        try {
            $payload = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new JsonException(self::REASON_INVALID_JSON, previous: $error);
        }

        if (!is_array($payload) || array_is_list($payload)) {
            throw new JsonException(self::REASON_EXPECTED_OBJECT);
        }

        return $payload;
    }
}
