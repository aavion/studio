<?php

declare(strict_types=1);

namespace App\Tests\Api\Http;

use App\Api\ApiMessageCode;
use App\Api\ApiMessageKey;
use App\Api\Http\ApiResponder;
use App\Core\Message\Message;
use App\Core\Output\JsonOutputRenderer;
use App\Tests\Support\IdentityTranslator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class ApiResponderTest extends TestCase
{
    public function testValidationErrorsAreExposedAsStableDetails(): void
    {
        $response = $this->responder()->error(
            Message::warning(ApiMessageCode::API_VALIDATION_FAILED, ApiMessageKey::API_VALIDATION_FAILED, context: [
                'path' => '/api/v1/admin/settings/api',
                'errors' => [
                    'api.cors.allowed_origins' => ['admin.settings.form.errors.invalid'],
                ],
            ]),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );

        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $payload['error']['status']);
        self::assertSame('api.validation_failed', $payload['error']['code']);
        self::assertSame(
            ['admin.settings.form.errors.invalid'],
            $payload['error']['details']['fields']['api.cors.allowed_origins'],
        );
        self::assertSame(
            ['admin.settings.form.errors.invalid'],
            $payload['error']['context']['errors']['api.cors.allowed_origins'],
        );
    }

    private function responder(): ApiResponder
    {
        return new ApiResponder(new JsonOutputRenderer(), new IdentityTranslator());
    }
}
