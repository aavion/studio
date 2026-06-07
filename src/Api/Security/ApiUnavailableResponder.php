<?php

declare(strict_types=1);

namespace App\Api\Security;

use App\Api\ApiMessageCode;
use App\Api\ApiMessageKey;
use App\Api\Http\ApiResponder;
use App\Core\Message\Message;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class ApiUnavailableResponder
{
    public function __construct(private ApiResponder $responder)
    {
    }

    public function setupIncomplete(Request $request): Response
    {
        return $this->responder->error(
            Message::error(
                ApiMessageCode::API_UNAVAILABLE_SETUP_INCOMPLETE,
                ApiMessageKey::API_UNAVAILABLE_SETUP_INCOMPLETE,
                context: ['reason' => 'setup_incomplete'],
            ),
            Response::HTTP_SERVICE_UNAVAILABLE,
            $request,
            headers: $this->headers(),
        );
    }

    public function databaseUnavailable(Request $request, Throwable $error): Response
    {
        return $this->responder->error(
            Message::error(
                ApiMessageCode::API_UNAVAILABLE_DATABASE,
                ApiMessageKey::API_UNAVAILABLE_DATABASE,
                context: [
                    'reason' => 'database_unavailable',
                    'exception' => $error::class,
                ],
            ),
            Response::HTTP_SERVICE_UNAVAILABLE,
            $request,
            headers: $this->headers(),
        );
    }

    public function maintenance(Request $request): Response
    {
        return $this->responder->error(
            Message::warning(
                ApiMessageCode::API_UNAVAILABLE_MAINTENANCE,
                ApiMessageKey::API_UNAVAILABLE_MAINTENANCE,
                context: ['reason' => 'maintenance'],
            ),
            Response::HTTP_SERVICE_UNAVAILABLE,
            $request,
            headers: $this->headers(),
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['Retry-After' => '60'];
    }
}
