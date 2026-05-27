<?php

declare(strict_types=1);

namespace App\Security;

use App\View\Http\HttpErrorRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final readonly class HttpErrorSecurityHandler implements AuthenticationEntryPointInterface, AccessDeniedHandlerInterface
{
    public function __construct(private HttpErrorRenderer $httpError)
    {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->httpError->unauthorized($request, $authException);
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        return $this->httpError->unauthorized($request, $accessDeniedException);
    }
}
