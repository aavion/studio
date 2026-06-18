<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Config\Config;
use App\Core\Config\ConfigValidationGuard;
use App\Core\Validation\EmailAddress;

final readonly class UserFlowConfig
{
    public const MENU_ENABLED_KEY = 'user.menu.enabled';
    public const MENU_SORT_ORDER_KEY = 'user.menu.sort_order';
    public const DEFAULT_ACL_GROUP_KEY = 'user.default_acl_group';
    public const REGISTRATION_MODE_KEY = 'user.registration.mode';
    public const REGISTRATION_ADMIN_NOTIFICATION_EMAIL_KEY = 'user.registration.admin_notification_email';
    public const SECURITY_NOTIFICATION_EMAIL_KEY = 'user.security_notification_email';
    public const ACCOUNT_LINK_TTL_HOURS_KEY = 'user.account_link_ttl_hours';
    public const USERNAME_CHANGE_ENABLED_KEY = 'user.username_change.enabled';
    public const DELETED_USER_RETENTION_DAYS_KEY = 'user.deleted_user_retention_days';
    public const REGISTRATION_DISABLED = 'disabled';
    public const REGISTRATION_ADMIN_APPROVAL = 'admin_approval';
    public const REGISTRATION_AUTO_APPROVAL = 'auto_approval';
    public const DEFAULT_ACCOUNT_LINK_TTL_HOURS = 24;
    public const DEFAULT_DELETED_USER_RETENTION_DAYS = 7;
    public const MIN_ACCOUNT_LINK_TTL_HOURS = 1;
    public const MAX_ACCOUNT_LINK_TTL_HOURS = 168;
    public const MIN_DELETED_USER_RETENTION_DAYS = 1;
    public const MAX_DELETED_USER_RETENTION_DAYS = 3650;
    public const DEFAULT_MENU_SORT_ORDER = 900;
    public const MIN_MENU_SORT_ORDER = 0;
    public const MAX_MENU_SORT_ORDER = 9999;
    public const PASSWORD_RESET_TTL = '+1 hour';

    public function __construct(
        private Config $config,
        private ConfigValidationGuard $configValidation = new ConfigValidationGuard(),
    ) {
    }

    public function menuEnabled(): bool
    {
        return true === ($this->config->get(self::MENU_ENABLED_KEY) ?? true);
    }

    public function menuSortOrder(): int
    {
        return $this->configValidation->boundedInteger(
            $this->config->get(self::MENU_SORT_ORDER_KEY),
            self::DEFAULT_MENU_SORT_ORDER,
            self::MIN_MENU_SORT_ORDER,
            self::MAX_MENU_SORT_ORDER,
        );
    }

    public function registrationEnabled(): bool
    {
        return self::REGISTRATION_DISABLED !== $this->registrationMode();
    }

    public function usernameChangeEnabled(): bool
    {
        return true === ($this->config->get(self::USERNAME_CHANGE_ENABLED_KEY) ?? false);
    }

    public function registrationMode(): string
    {
        $mode = $this->config->get(self::REGISTRATION_MODE_KEY) ?? self::REGISTRATION_DISABLED;

        return in_array($mode, [
            self::REGISTRATION_DISABLED,
            self::REGISTRATION_ADMIN_APPROVAL,
            self::REGISTRATION_AUTO_APPROVAL,
        ], true) ? $mode : self::REGISTRATION_DISABLED;
    }

    public function defaultAclGroupIdentifier(): ?string
    {
        $identifier = $this->config->get(self::DEFAULT_ACL_GROUP_KEY) ?? '';

        return is_string($identifier) && '' !== trim($identifier) ? trim($identifier) : null;
    }

    public function accountLinkTtl(): string
    {
        return sprintf('+%d hours', $this->accountLinkTtlHours());
    }

    public function accountLinkTtlHours(): int
    {
        return $this->configValidation->boundedInteger(
            $this->config->get(self::ACCOUNT_LINK_TTL_HOURS_KEY),
            self::DEFAULT_ACCOUNT_LINK_TTL_HOURS,
            self::MIN_ACCOUNT_LINK_TTL_HOURS,
            self::MAX_ACCOUNT_LINK_TTL_HOURS,
        );
    }

    public function deletedUserRetentionDays(): int
    {
        return $this->configValidation->boundedInteger(
            $this->config->get(self::DELETED_USER_RETENTION_DAYS_KEY),
            self::DEFAULT_DELETED_USER_RETENTION_DAYS,
            self::MIN_DELETED_USER_RETENTION_DAYS,
            self::MAX_DELETED_USER_RETENTION_DAYS,
        );
    }

    public function registrationAdminNotificationEmail(): ?string
    {
        return $this->normalizedEmailSetting(self::REGISTRATION_ADMIN_NOTIFICATION_EMAIL_KEY);
    }

    public function securityNotificationEmail(): ?string
    {
        return $this->normalizedEmailSetting(self::SECURITY_NOTIFICATION_EMAIL_KEY);
    }

    private function normalizedEmailSetting(string $key): ?string
    {
        $email = $this->config->get($key) ?? '';

        if (!is_string($email)) {
            return null;
        }

        return EmailAddress::isValid($email) ? EmailAddress::normalize($email) : null;
    }
}
