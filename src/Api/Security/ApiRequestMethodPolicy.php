<?php

declare(strict_types=1);

namespace App\Api\Security;

use Symfony\Component\HttpFoundation\Request;

final readonly class ApiRequestMethodPolicy
{
    public function isApiV1Request(Request $request): bool
    {
        return $this->pathMatchesPrefix($request->getPathInfo(), '/api/v1');
    }

    public function isCorsPreflight(Request $request): bool
    {
        return $request->isMethod(Request::METHOD_OPTIONS)
            && is_string($request->headers->get('Origin'))
            && is_string($request->headers->get('Access-Control-Request-Method'));
    }

    public function isCredentialedOptions(Request $request): bool
    {
        return $request->isMethod(Request::METHOD_OPTIONS) && $this->hasAuthorizationHeader($request);
    }

    public function hasAuthorizationHeader(Request $request): bool
    {
        return '' !== trim((string) $request->headers->get('Authorization', ''));
    }

    public function effectiveMethod(Request $request): string
    {
        if ($request->isMethod(Request::METHOD_OPTIONS)) {
            return $this->requestedPreflightMethod($request)
                ?? ($this->isCredentialedOptions($request) ? Request::METHOD_GET : Request::METHOD_OPTIONS);
        }

        return strtoupper($request->getMethod());
    }

    public function isSafeEffectiveMethod(Request $request): bool
    {
        return $this->isSafeMethod($this->effectiveMethod($request));
    }

    public function isSafeMethod(string $method): bool
    {
        return in_array(strtoupper($method), [
            Request::METHOD_GET,
            Request::METHOD_HEAD,
            Request::METHOD_OPTIONS,
        ], true);
    }

    public function requestedPreflightMethod(Request $request): ?string
    {
        $method = $request->headers->get('Access-Control-Request-Method');

        return is_string($method) && '' !== trim($method) ? strtoupper(trim($method)) : null;
    }

    private function pathMatchesPrefix(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, $prefix.'/');
    }
}
