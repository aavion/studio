<?php

declare(strict_types=1);

namespace App\Api\Security;

use App\Api\Http\ApiResponder;
use App\Core\Message\Message;
use App\Security\SecurityMessageCode;
use App\Security\SecurityMessageKey;
use App\View\SystemExtensionMetadataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final readonly class ApiSecurityHandler implements AuthenticationEntryPointInterface, AccessDeniedHandlerInterface
{
    public function __construct(
        private ApiResponder $responder,
        private SystemExtensionMetadataProvider $systemExtensionMetadata,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->unauthorized($request);
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        return $this->responder->error(
            Message::warning(
                SecurityMessageCode::API_KEY_PERMISSION_DENIED,
                SecurityMessageKey::API_KEY_PERMISSION_DENIED,
            ),
            Response::HTTP_FORBIDDEN,
            $request,
        );
    }

    public function authenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof ApiAuthenticationException) {
            return $this->responder->error(
                $exception->apiMessage(),
                $exception->statusCode(),
                $request,
                headers: $this->headersFor($exception->statusCode()),
            );
        }

        return $this->unauthorized($request);
    }

    private function unauthorized(Request $request): Response
    {
        return $this->responder->error(
            Message::warning(
                SecurityMessageCode::API_KEY_AUTHENTICATION_FAILED,
                SecurityMessageKey::API_KEY_AUTHENTICATION_FAILED,
            ),
            Response::HTTP_UNAUTHORIZED,
            $request,
            headers: $this->headersFor(Response::HTTP_UNAUTHORIZED),
        );
    }

    /**
     * @return array<string, string>
     */
    private function headersFor(int $status): array
    {
        if (Response::HTTP_UNAUTHORIZED !== $status) {
            return [];
        }

        return ['WWW-Authenticate' => sprintf('Bearer realm="%s"', $this->realm())];
    }

    private function apiTitle(): string
    {
        $name = trim((string) $this->systemExtensionMetadata->metadata()['name']);

        return ('' !== $name ? $name : 'System').' API';
    }

    private function realm(): string
    {
        return strtr($this->apiTitle(), [
            '\\' => '\\\\',
            '"' => '\\"',
        ]);
    }
}
