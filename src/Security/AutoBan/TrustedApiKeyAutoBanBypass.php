<?php

declare(strict_types=1);

namespace App\Security\AutoBan;

use App\Api\Security\ApiKeyCredentialResolver;
use App\Entity\ApiKey;
use App\Scheduler\SchedulerSettings;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

final readonly class TrustedApiKeyAutoBanBypass
{
    private const MAX_SCHEDULER_QUERY_TOKEN_LENGTH = 128;

    public function __construct(
        private ApiKeyCredentialResolver $credentials,
        private AutoBanPolicy $policy,
        private SchedulerSettings $schedulerSettings,
    ) {
    }

    public function allows(Request $request, bool $allowPrefixlessBearer = false, bool $allowSchedulerQuery = false): bool
    {
        try {
            $apiKey = $allowPrefixlessBearer
                ? $this->credentials->resolveBearerHmac($request)
                : $this->credentials->resolve($request);

            if (null === $apiKey && $allowSchedulerQuery && $this->schedulerSettings->getAuthEnabled()) {
                $auth = $request->query->get('auth');
                $token = is_string($auth) ? trim($auth) : '';
                $apiKey = $this->acceptableSchedulerQueryToken($token)
                    ? $this->credentials->resolvePlainKeyHmac($token)
                    : null;
            }

            return $this->trusted($apiKey);
        } catch (Throwable) {
            return false;
        }
    }

    private function trusted(?ApiKey $apiKey): bool
    {
        if (null === $apiKey || !$apiKey->status()->isActive() || !$apiKey->user()->status()->isUsable()) {
            return false;
        }

        return $apiKey->user()->accessLevel() >= $this->policy->trustedAccessLevel();
    }

    private function acceptableSchedulerQueryToken(string $token): bool
    {
        return '' !== $token
            && strlen($token) <= self::MAX_SCHEDULER_QUERY_TOKEN_LENGTH
            && 1 === preg_match('/^[^\s\x00-\x1F\x7F]+$/', $token);
    }
}
