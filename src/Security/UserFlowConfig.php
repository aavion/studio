<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Config\ConfigReader;

final readonly class UserFlowConfig
{
    public const MENU_ENABLED_KEY = 'user.menu.enabled';
    public const MENU_SORT_ORDER_KEY = 'user.menu.sort_order';
    public const REGISTRATION_ENABLED_KEY = 'user.registration.enabled';

    public function __construct(private ConfigReader $config)
    {
    }

    public function menuEnabled(): bool
    {
        return $this->config->bool(self::MENU_ENABLED_KEY, true);
    }

    public function menuSortOrder(): int
    {
        return $this->config->int(self::MENU_SORT_ORDER_KEY, 900);
    }

    public function registrationEnabled(): bool
    {
        return $this->config->bool(self::REGISTRATION_ENABLED_KEY, false);
    }
}
