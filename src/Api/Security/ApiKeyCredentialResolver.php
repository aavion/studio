<?php

declare(strict_types=1);

namespace App\Api\Security;

use App\Entity\ApiKey;
use App\Security\ApiKeyVault;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class ApiKeyCredentialResolver
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ApiKeyVault $apiKeyVault,
    ) {
    }

    public function supportsBearer(Request $request): bool
    {
        $authorization = $request->headers->get('Authorization');

        return is_string($authorization) && 1 === preg_match('/^Bearer(?:\s+|$)/i', $authorization);
    }

    public function resolve(Request $request): ?ApiKey
    {
        $plainKey = $this->bearerToken($request);
        if (null === $plainKey) {
            return null;
        }

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

    public function resolveBearerHmac(Request $request): ?ApiKey
    {
        $plainKey = $this->bearerToken($request);
        if (null === $plainKey) {
            return null;
        }

        return $this->resolvePlainKeyHmac($plainKey);
    }

    public function resolvePlainKeyHmac(string $plainKey): ?ApiKey
    {
        $apiKey = $this->entityManager->getRepository(ApiKey::class)->findOneBy([
            'hmacHash' => $this->apiKeyVault->hmac($plainKey),
        ]);

        return $apiKey instanceof ApiKey ? $apiKey : null;
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

    private function prefix(string $plainKey): ?string
    {
        $dotPosition = strpos($plainKey, '.');
        if (false === $dotPosition) {
            return null;
        }

        $prefix = substr($plainKey, 0, $dotPosition);

        return 1 === preg_match('/^[A-Za-z0-9_-]{4,16}$/', $prefix) ? $prefix : null;
    }
}
