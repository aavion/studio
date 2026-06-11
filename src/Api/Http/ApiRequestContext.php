<?php

declare(strict_types=1);

namespace App\Api\Http;

use App\Core\Access\AccessActor;
use App\Entity\ApiKey;
use App\Entity\UserAccount;
use App\Security\ApiKeyStatus;
use Symfony\Component\HttpFoundation\Request;

final readonly class ApiRequestContext
{
    public const ATTRIBUTE = '_system_api_request_context';

    private function __construct(
        private ?UserAccount $user,
        private ?string $apiKeyUid,
        private ?string $apiKeyPrefix,
        private ?ApiKeyStatus $apiKeyStatus,
        private AccessActor $actor,
    ) {
    }

    public static function anonymous(): self
    {
        return new self(null, null, null, null, AccessActor::anonymous());
    }

    public static function fromApiKey(ApiKey $apiKey): self
    {
        return new self(
            $apiKey->user(),
            $apiKey->uid(),
            $apiKey->prefix(),
            $apiKey->status(),
            AccessActor::fromUserAccount($apiKey->user()),
        );
    }

    public static function fromRequest(Request $request): ?self
    {
        $context = $request->attributes->get(self::ATTRIBUTE);

        return $context instanceof self ? $context : null;
    }

    public function attachTo(Request $request): void
    {
        $request->attributes->set(self::ATTRIBUTE, $this);
    }

    public function isAuthenticated(): bool
    {
        return null !== $this->user;
    }

    public function user(): ?UserAccount
    {
        return $this->user;
    }

    public function apiKeyUid(): ?string
    {
        return $this->apiKeyUid;
    }

    public function apiKeyPrefix(): ?string
    {
        return $this->apiKeyPrefix;
    }

    public function apiKeyStatus(): ?ApiKeyStatus
    {
        return $this->apiKeyStatus;
    }

    public function actor(): AccessActor
    {
        return $this->actor;
    }
}
