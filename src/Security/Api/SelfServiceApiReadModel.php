<?php

declare(strict_types=1);

namespace App\Security\Api;

use App\Entity\AclGroup;
use App\Entity\ApiKey;
use App\Entity\UserAccount;
use App\Security\ApiKeyStatus;

final readonly class SelfServiceApiReadModel
{
    /**
     * @return array<string, mixed>
     */
    public function user(UserAccount $user): array
    {
        return [
            'type' => 'user_profile',
            'id' => $user->username(),
            'attributes' => [
                'username' => $user->username(),
                'email' => $user->email(),
                'display_name' => (string) ($user->profile()['display_name'] ?? ''),
                'language' => (string) ($user->settings()['language'] ?? 'default'),
                'status' => $user->status()->value,
                'role' => $user->role()->value,
                'access_level' => $user->accessLevel(),
                'groups' => $this->groups($user),
            ],
            'links' => [
                'self' => '/api/v1/user',
                'api_keys' => '/api/v1/user/api-keys',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function apiKey(ApiKey $apiKey, bool $includeUid = false, ?string $plainKey = null): array
    {
        $resource = [
            'type' => 'api_key',
            'id' => $apiKey->prefix(),
            'attributes' => [
                'prefix' => $apiKey->prefix(),
                'status' => $apiKey->status()->value,
                'created_at' => $apiKey->createdAt()->format(DATE_ATOM),
                'revoked_at' => $apiKey->revokedAt()?->format(DATE_ATOM),
            ],
            'links' => [
                'self' => '/api/v1/user/api-keys/items/'.$apiKey->uid(),
            ],
        ];

        if ($includeUid) {
            $resource['attributes']['uid'] = $apiKey->uid();
        }

        if (null !== $plainKey) {
            $resource['attributes']['plain_key'] = $plainKey;
            $resource['attributes']['secret_display'] = 'one_time';
        }

        if (ApiKeyStatus::Revoked !== $apiKey->status()) {
            $resource['links']['revoke'] = '/api/v1/user/api-keys/items/'.$apiKey->uid();
        }

        return $resource;
    }

    /**
     * @return list<array{identifier: string, name: string, min_role: int}>
     */
    private function groups(UserAccount $user): array
    {
        $groups = [];

        foreach ($user->groups() as $group) {
            if ($group instanceof AclGroup) {
                $groups[] = [
                    'identifier' => $group->identifier(),
                    'name' => $group->name(),
                    'min_role' => $group->minRole(),
                ];
            }
        }

        usort($groups, static fn (array $left, array $right): int => $left['identifier'] <=> $right['identifier']);

        return $groups;
    }
}
