<?php

declare(strict_types=1);

namespace App\Core\Log;

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

    public function __construct(private Connection $connection)
    {
    }

    public function retentionDaysForSource(string $source): int
    {
        return match ($source) {
            'audit' => $this->days(self::AUDIT_LOG_RETENTION_DAYS_KEY, self::DEFAULT_LOG_RETENTION_DAYS),
            'access' => $this->days(self::ACCESS_LOG_RETENTION_DAYS_KEY, self::DEFAULT_LOG_RETENTION_DAYS),
            'message' => $this->days(self::MESSAGE_LOG_RETENTION_DAYS_KEY, self::DEFAULT_LOG_RETENTION_DAYS),
            default => self::DEFAULT_LOG_RETENTION_DAYS,
        };
    }

    public function retentionDaysForSignal(): int
    {
        return $this->days(self::SECURITY_SIGNAL_RETENTION_DAYS_KEY, self::DEFAULT_SECURITY_SIGNAL_RETENTION_DAYS);
    }

    private function days(string $key, int $default): int
    {
        try {
            $encoded = $this->connection->fetchOne('SELECT value FROM config_entry WHERE config_key = ?', [$key]);
        } catch (Throwable) {
            return $default;
        }

        if (!is_string($encoded)) {
            return $default;
        }

        try {
            $value = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $default;
        }

        $days = is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);

        return max(1, min(self::MAX_RETENTION_DAYS, $days));
    }
}
