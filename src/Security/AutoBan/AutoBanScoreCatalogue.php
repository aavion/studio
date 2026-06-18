<?php

declare(strict_types=1);

namespace App\Security\AutoBan;

final readonly class AutoBanScoreCatalogue
{
    public const SIGNAL_ERROR_HIT = 'security.signal.error_http_status';
    public const SIGNAL_SUSPICIOUS_PROBE = 'security.signal.suspicious_probe';
    public const SIGNAL_SESSION_VISITOR_MISMATCH = 'security.signal.session_visitor_mismatch';
    public const SIGNAL_AUTH_FAILURE = 'security.signal.auth_failure';
    public const SIGNAL_TRIGGERED = 'security.signal.auto_ban_triggered';
    public const SIGNAL_RESET = 'security.signal.auto_ban_reset';

    public const WEIGHT_ERROR_HIT = 7;
    public const WEIGHT_PROBE = 100;
    public const WEIGHT_SESSION_COPY = 100;
    public const WEIGHT_AUTH_FAILURE = 10;

    public function scoreFor(string $signalType, string $reasonCode, ?int $httpStatus = null): int
    {
        if (self::SIGNAL_ERROR_HIT === $reasonCode && in_array($httpStatus, [400, 403, 404, 429], true)) {
            return self::WEIGHT_ERROR_HIT;
        }

        if ('probe' === $signalType || self::SIGNAL_SUSPICIOUS_PROBE === $reasonCode) {
            return self::WEIGHT_PROBE;
        }

        if (self::SIGNAL_SESSION_VISITOR_MISMATCH === $reasonCode) {
            return self::WEIGHT_SESSION_COPY;
        }

        if (self::SIGNAL_AUTH_FAILURE === $reasonCode) {
            return self::WEIGHT_AUTH_FAILURE;
        }

        return 0;
    }

    public function scoreableSubject(string $subjectType): bool
    {
        return in_array($subjectType, [AutoBanSubject::VISITOR, AutoBanSubject::IP], true);
    }
}
