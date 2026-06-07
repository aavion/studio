<?php

declare(strict_types=1);

namespace App\Api\Security;

use App\Core\Message\Message;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

final class ApiAuthenticationException extends AuthenticationException
{
    public function __construct(
        private readonly Message $apiMessage,
        private readonly int $statusCode = Response::HTTP_UNAUTHORIZED,
    ) {
        parent::__construct($apiMessage->translationKey());
    }

    public function apiMessage(): Message
    {
        return $this->apiMessage;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
