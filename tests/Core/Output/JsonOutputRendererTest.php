<?php

declare(strict_types=1);

namespace App\Tests\Core\Output;

use App\Core\Output\JsonOutputRenderer;
use JsonException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class JsonOutputRendererTest extends TestCase
{
    public function testItRendersRawJsonResponses(): void
    {
        $response = (new JsonOutputRenderer())->render([
            'captcha_seed' => 'abc-123',
            'ttl' => 60,
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('{"captcha_seed":"abc-123","ttl":60}', $response->getContent());
    }

    public function testItCanEmitPreEncodedJsonWithCustomStatusAndHeaders(): void
    {
        $response = (new JsonOutputRenderer())->renderRaw('{"ok":true}', Response::HTTP_ACCEPTED, [
            'Cache-Control' => 'private, max-age=5',
        ]);

        self::assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        self::assertStringContainsString('max-age=5', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertSame('{"ok":true}', $response->getContent());
    }

    public function testItRejectsInvalidRawJson(): void
    {
        $this->expectException(JsonException::class);

        (new JsonOutputRenderer())->renderRaw('{');
    }
}
