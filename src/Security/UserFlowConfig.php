<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Config\Config;

final readonly class UserFlowConfig
{
    public const MENU_ENABLED_KEY = 'user.menu.enabled';
    public const MENU_SORT_ORDER_KEY = 'user.menu.sort_order';
    public const REGISTRATION_ENABLED_KEY = 'user.registration.enabled';

    public function __construct(private Config $config)
    {
    }

    public function menuEnabled(): bool
    {
        return true === $this->config->get(self::MENU_ENABLED_KEY, true);
    }

    public function menuSortOrder(): int
    {
        $sortOrder = $this->config->get(self::MENU_SORT_ORDER_KEY, 900);

        return is_int($sortOrder) ? $sortOrder : 900;
    }

    public function registrationEnabled(): bool
    {
        return true === $this->config->get(self::REGISTRATION_ENABLED_KEY, false);
    }
}
