<?php

declare(strict_types=1);

namespace App\Security\AutoBan;

use App\Core\Access\AccessLevel;
use App\Core\Config\Config;

final readonly class AutoBanPolicy
{
    public const ENABLED_KEY = 'security.auto_ban.enabled';
    public const TRUSTED_ACCESS_LEVEL_KEY = 'security.auto_ban.trusted_access_level';
    public const SCORE_THRESHOLD_KEY = 'security.auto_ban.score_threshold';
    public const NEW_BAN_OWNER_ALERTS_KEY = 'security.auto_ban.new_ban_owner_alerts';

    public const DEFAULT_ENABLED = false;
    public const SETUP_ENABLED = true;
    public const DEFAULT_NEW_BAN_OWNER_ALERTS = true;
    public const DEFAULT_TRUSTED_ACCESS_LEVEL = AccessLevel::MANAGER;
    public const DEFAULT_SCORE_THRESHOLD = 100;
    public const IP_THRESHOLD_MULTIPLIER = 2;
    public const SCORE_WINDOW_SECONDS = 3600;
    public const MINIMUM_QUALIFYING_SIGNALS = 2;

    /** @var list<int> */
    public const TTL_ESCALATION_SECONDS = [3600, 10800, 86400, 604800];

    public function __construct(private Config $config)
    {
    }

    public function enabled(): bool
    {
        return true === $this->config->get(self::ENABLED_KEY, self::DEFAULT_ENABLED);
    }

    public function newBanOwnerAlertsEnabled(): bool
    {
        return true === $this->config->get(self::NEW_BAN_OWNER_ALERTS_KEY, self::DEFAULT_NEW_BAN_OWNER_ALERTS);
    }

    public function trustedAccessLevel(): int
    {
        $level = $this->config->get(self::TRUSTED_ACCESS_LEVEL_KEY, self::DEFAULT_TRUSTED_ACCESS_LEVEL);

        return AccessLevel::assert(is_numeric($level) ? (int) $level : self::DEFAULT_TRUSTED_ACCESS_LEVEL) ?? self::DEFAULT_TRUSTED_ACCESS_LEVEL;
    }

    public function visitorThreshold(): int
    {
        $threshold = $this->config->get(self::SCORE_THRESHOLD_KEY, self::DEFAULT_SCORE_THRESHOLD);

        return max(2, is_numeric($threshold) ? (int) $threshold : self::DEFAULT_SCORE_THRESHOLD);
    }

    public function thresholdFor(string $subjectType): int
    {
        $threshold = $this->visitorThreshold();

        return AutoBanSubject::IP === $subjectType ? $threshold * self::IP_THRESHOLD_MULTIPLIER : $threshold;
    }

    public function ttlForEscalationCount(int $priorBanSignals): int
    {
        $index = max(0, min(count(self::TTL_ESCALATION_SECONDS) - 1, $priorBanSignals));

        return self::TTL_ESCALATION_SECONDS[$index];
    }
}
