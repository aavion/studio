<?php

declare(strict_types=1);

namespace App\Api\Security;

use App\Api\Http\ApiRequestContext;
use App\Core\Message\Message;
use App\Entity\ApiKey;
use App\Security\ApiKeyStatus;
use App\Security\SecurityMessageCode;
use App\Security\SecurityMessageKey;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class ApiKeyAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly ApiKeyCredentialResolver $credentials,
        private readonly ApiSecurityHandler $securityHandler,
        private readonly ApiRequestMethodPolicy $methodPolicy = new ApiRequestMethodPolicy(),
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $this->methodPolicy->isApiV1Request($request)
            && $this->credentials->supportsBearer($request);
    }

    public function authenticate(Request $request): Passport
    {
        $apiKey = $this->credentials->resolve($request);

        if (!$apiKey instanceof ApiKey) {
            throw $this->authenticationFailed();
        }

        if (ApiKeyStatus::Revoked === $apiKey->status()) {
            throw new ApiAuthenticationException(Message::warning(
                SecurityMessageCode::API_KEY_PERMISSION_REVOKED,
                SecurityMessageKey::API_KEY_PERMISSION_REVOKED,
            ));
        }

        $context = ApiRequestContext::fromApiKey($apiKey);
        $context->attachTo($request);

        return new SelfValidatingPassport(new UserBadge(
            $apiKey->user()->getUserIdentifier(),
            static fn () => $apiKey->user(),
        ));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return $this->securityHandler->authenticationFailure($request, $exception);
    }

    private function authenticationFailed(): ApiAuthenticationException
    {
        return new ApiAuthenticationException(Message::warning(
            SecurityMessageCode::API_KEY_AUTHENTICATION_FAILED,
            SecurityMessageKey::API_KEY_AUTHENTICATION_FAILED,
        ));
    }
}
