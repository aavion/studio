<?php

declare(strict_types=1);

namespace App\Core\Log;

use App\Core\Config\ConfigValidationGuard;
use App\Security\AutoBan\AutoBanPolicy;
use Doctrine\DBAL\Connection;
use Throwable;

final readonly class DatabaseLogRetentionPolicy
{
    public const MESSAGE_LOG_RETENTION_DAYS_KEY = 'logging.database.message_retention_days';
    public const AUDIT_LOG_RETENTION_DAYS_KEY = 'logging.database.audit_retention_days';
    public const ACCESS_LOG_RETENTION_DAYS_KEY = 'logging.database.access_retention_days';
    public const SECURITY_SIGNAL_RETENTION_DAYS_KEY = 'security.signals.retention_days';
    public const DEFAULT_LOG_RETENTION_DAYS = 30;
    public const DEFAULT_SECURITY_SIGNAL_RETENTION_DAYS = 7;
    public const MAX_RETENTION_DAYS = 30;

    public function __construct(
        private Connection $connection,
        private ConfigValidationGuard $configValidation = new ConfigValidationGuard(),
    ) {
    }

    public function retentionDaysForSource(string $source): int
    {
        return match ($source) {
            'audit' => $this->days(self::AUDIT_LOG_RETENTION_DAYS_KEY, self::DEFAULT_LOG_RETENTION_DAYS, 1),
            'access' => $this->days(self::ACCESS_LOG_RETENTION_DAYS_KEY, self::DEFAULT_LOG_RETENTION_DAYS, 1),
            'message' => $this->days(self::MESSAGE_LOG_RETENTION_DAYS_KEY, self::DEFAULT_LOG_RETENTION_DAYS, 1),
            default => self::DEFAULT_LOG_RETENTION_DAYS,
        };
    }

    public function retentionDaysForSignal(): int
    {
        return $this->days(
            self::SECURITY_SIGNAL_RETENTION_DAYS_KEY,
            self::defaultSecuritySignalRetentionDays(),
            AutoBanPolicy::maxTtlDays(),
        );
    }

    public static function defaultSecuritySignalRetentionDays(): int
    {
        return max(self::DEFAULT_SECURITY_SIGNAL_RETENTION_DAYS, AutoBanPolicy::maxTtlDays());
    }

    private function days(string $key, int $default, int $min): int
    {
        try {
            $encoded = $this->connection->fetchOne('SELECT value FROM config_entry WHERE config_key = ?', [$key]);
        } catch (Throwable) {
            return $this->configValidation->boundedInteger($default, $default, $min, self::MAX_RETENTION_DAYS);
        }

        if (!is_string($encoded)) {
            return $this->configValidation->boundedInteger($default, $default, $min, self::MAX_RETENTION_DAYS);
        }

        try {
            $value = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $this->configValidation->boundedInteger($default, $default, $min, self::MAX_RETENTION_DAYS);
        }

        return $this->configValidation->boundedInteger($value, $default, $min, self::MAX_RETENTION_DAYS);
    }
}
