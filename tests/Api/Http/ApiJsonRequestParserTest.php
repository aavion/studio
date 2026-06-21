<?php

declare(strict_types=1);

namespace App\Tests\Api\Http;

use App\Api\Http\ApiJsonRequestParser;
use JsonException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ApiJsonRequestParserTest extends TestCase
{
    public function testEmptyRequestBodyReturnsEmptyPayload(): void
    {
        self::assertSame([], $this->parser()->object(Request::create('/api/test', content: '   ')));
    }

    public function testJsonObjectReturnsPayload(): void
    {
        self::assertSame(
            ['enabled' => true, 'limit' => 10],
            $this->parser()->object(Request::create('/api/test', content: '{"enabled":true,"limit":10}')),
        );
    }

    public function testInvalidJsonReturnsStableReasonCode(): void
    {
        try {
            $this->parser()->object(Request::create('/api/test', content: '{'));
            self::fail('Expected invalid JSON to fail.');
        } catch (JsonException $error) {
            self::assertSame(ApiJsonRequestParser::REASON_INVALID_JSON, $error->getMessage());
            self::assertInstanceOf(JsonException::class, $error->getPrevious());
        }
    }

    public function testJsonListReturnsStableReasonCode(): void
    {
        $this->expectException(JsonException::class);
        $this->expectExceptionMessage(ApiJsonRequestParser::REASON_EXPECTED_OBJECT);

        $this->parser()->object(Request::create('/api/test', content: '["not","an","object"]'));
    }

    private function parser(): ApiJsonRequestParser
    {
        return new ApiJsonRequestParser();
    }
}
