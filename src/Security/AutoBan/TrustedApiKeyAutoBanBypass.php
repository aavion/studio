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
                $apiKey = is_string($auth) && '' !== trim($auth)
                    ? $this->credentials->resolvePlainKeyHmac(trim($auth))
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
}
