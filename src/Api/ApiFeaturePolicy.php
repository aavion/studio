<?php

declare(strict_types=1);

namespace App\Api;

use App\Core\Access\AccessLevel;
use App\Core\Config\Config;
use App\Entity\UserAccount;

final readonly class ApiFeaturePolicy
{
    public const ENABLED_KEY = 'api.enabled';
    public const CORS_ENABLED_KEY = 'api.cors.enabled';
    public const CORS_ALLOWED_ORIGINS_KEY = 'api.cors.allowed_origins';

    public function __construct(private Config $config)
    {
    }

    public function isEnabled(): bool
    {
        return true === $this->config->get(self::ENABLED_KEY, true);
    }

    public function canManageKeys(?UserAccount $user): bool
    {
        if (!$user instanceof UserAccount) {
            return false;
        }

        return $this->isEnabled() || $user->role()->accessLevel() >= AccessLevel::OWNER;
    }

    public function corsEnabled(): bool
    {
        return true === $this->config->get(self::CORS_ENABLED_KEY, false);
    }

    /**
     * @return list<string>
     */
    public function allowedCorsOrigins(): array
    {
        $origins = $this->config->get(self::CORS_ALLOWED_ORIGINS_KEY, []);

        if (!is_array($origins)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $origin): string => is_string($origin) ? trim($origin) : '', $origins),
            static fn (string $origin): bool => '' !== $origin,
        ));
    }
}
