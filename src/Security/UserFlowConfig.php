<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Config\Config;

final readonly class UserFlowConfig
{
    public const MENU_ENABLED_KEY = 'user.menu.enabled';
    public const MENU_SORT_ORDER_KEY = 'user.menu.sort_order';
    public const REGISTRATION_MODE_KEY = 'user.registration.mode';
    public const REGISTRATION_DISABLED = 'disabled';
    public const REGISTRATION_ADMIN_APPROVAL = 'admin_approval';
    public const REGISTRATION_AUTO_APPROVAL = 'auto_approval';

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
        return self::REGISTRATION_DISABLED !== $this->registrationMode();
    }

    public function registrationMode(): string
    {
        $mode = $this->config->get(self::REGISTRATION_MODE_KEY, self::REGISTRATION_DISABLED);

        return in_array($mode, [
            self::REGISTRATION_DISABLED,
            self::REGISTRATION_ADMIN_APPROVAL,
            self::REGISTRATION_AUTO_APPROVAL,
        ], true) ? $mode : self::REGISTRATION_DISABLED;
    }
}
