<?php

declare(strict_types=1);

namespace App\Core\Statistics;

use App\Core\Config\Config;
use Symfony\Component\HttpFoundation\Request;

final readonly class AccessStatisticsPolicy
{
    public const ENABLED_KEY = 'statistics.enabled';
    public const RESPECT_DO_NOT_TRACK_KEY = 'statistics.respect_do_not_track';

    public function __construct(private Config $config)
    {
    }

    public function isDisplayEnabled(): bool
    {
        return true === $this->config->get(self::ENABLED_KEY, true);
    }

    public function isRecordingEnabled(Request $request): bool
    {
        if (!$this->isDisplayEnabled()) {
            return false;
        }

        return !$this->respectsDoNotTrack() || !$this->requestHasDoNotTrack($request);
    }

    public function requestHasDoNotTrack(Request $request): bool
    {
        return '1' === trim((string) $request->headers->get('DNT', ''));
    }

    private function respectsDoNotTrack(): bool
    {
        return true === $this->config->get(self::RESPECT_DO_NOT_TRACK_KEY, true);
    }
}
