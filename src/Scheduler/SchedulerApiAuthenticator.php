<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Entity\ApiKey;
use App\Security\ApiKeyStatus;
use App\Security\ApiKeyVault;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class SchedulerApiAuthenticator
{
    public function __construct(
        private ApiKeyVault $apiKeyVault,
        private EntityManagerInterface $entityManager,
        private SchedulerSettings $settings,
    ) {
    }

    public function authenticate(Request $request): ?ApiKey
    {
        $token = $this->bearerToken($request);

        if (null === $token && $this->settings->getAuthEnabled()) {
            $auth = $request->query->get('auth');
            $token = is_string($auth) && '' !== trim($auth) ? trim($auth) : null;
        }

        if (null === $token) {
            return null;
        }

        $apiKey = $this->entityManager->getRepository(ApiKey::class)->findOneBy([
            'hmacHash' => $this->apiKeyVault->hmac($token),
        ]);

        if (!$apiKey instanceof ApiKey || !in_array($apiKey->status(), [ApiKeyStatus::ReadOnly, ApiKeyStatus::ReadWrite], true)) {
            return null;
        }

        return $apiKey;
    }

    public function redactedTokenSubject(Request $request): string
    {
        $token = $this->bearerToken($request);
        if (null === $token && $this->settings->getAuthEnabled()) {
            $auth = $request->query->get('auth');
            $token = is_string($auth) ? $auth : null;
        }

        if (null === $token || '' === $token) {
            return 'missing';
        }

        $prefix = explode('.', $token, 2)[0] ?: substr($token, 0, 4);

        return $prefix.'…';
    }

    private function bearerToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');
        if (!is_string($header) || 1 !== preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return null;
        }

        $token = trim($matches[1]);

        return '' === $token ? null : $token;
    }
}
