<?php

declare(strict_types=1);

namespace App\Api\Security;

use App\Api\Http\ApiRequestContext;
use App\Core\Message\Message;
use App\Entity\ApiKey;
use App\Security\ApiKeyStatus;
use App\Security\ApiKeyVault;
use App\Security\SecurityMessageCode;
use App\Security\SecurityMessageKey;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiKeyVault $apiKeyVault,
        private readonly ApiSecurityHandler $securityHandler,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return str_starts_with($request->getPathInfo(), '/api/v1')
            && is_string($request->headers->get('Authorization'));
    }

    public function authenticate(Request $request): Passport
    {
        $plainKey = $this->bearerToken($request);

        if (null === $plainKey) {
            throw $this->authenticationFailed();
        }

        $apiKey = $this->apiKeyFor($plainKey);

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

    private function bearerToken(Request $request): ?string
    {
        $authorization = $request->headers->get('Authorization');

        if (!is_string($authorization) || 1 !== preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            return null;
        }

        $token = trim($matches[1]);

        return '' !== $token && strlen($token) <= 512 ? $token : null;
    }

    private function apiKeyFor(string $plainKey): ?ApiKey
    {
        $prefix = $this->prefix($plainKey);

        if (null === $prefix) {
            return null;
        }

        $apiKey = $this->entityManager->getRepository(ApiKey::class)->findOneBy([
            'prefix' => $prefix,
            'hmacHash' => $this->apiKeyVault->hmac($plainKey),
        ]);

        return $apiKey instanceof ApiKey ? $apiKey : null;
    }

    private function prefix(string $plainKey): ?string
    {
        $dotPosition = strpos($plainKey, '.');

        if (false === $dotPosition) {
            return null;
        }

        $prefix = substr($plainKey, 0, $dotPosition);

        return 1 === preg_match('/^[A-Za-z0-9_-]{4,16}$/', $prefix) ? $prefix : null;
    }

    private function authenticationFailed(): ApiAuthenticationException
    {
        return new ApiAuthenticationException(Message::warning(
            SecurityMessageCode::API_KEY_AUTHENTICATION_FAILED,
            SecurityMessageKey::API_KEY_AUTHENTICATION_FAILED,
        ));
    }
}
