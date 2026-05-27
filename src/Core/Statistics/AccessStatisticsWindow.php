<?php

declare(strict_types=1);

namespace App\Core\Statistics;

use DateInterval;
use DateTimeImmutable;

final readonly class AccessStatisticsWindow
{
    public const DEFAULT = '24h';

    /**
     * @return list<array{key: string, label_key: string}>
     */
    public function options(): array
    {
        return [
            ['key' => '1h', 'label_key' => 'admin.logs.statistics.windows.1h'],
            ['key' => '24h', 'label_key' => 'admin.logs.statistics.windows.24h'],
            ['key' => '7d', 'label_key' => 'admin.logs.statistics.windows.7d'],
            ['key' => '30d', 'label_key' => 'admin.logs.statistics.windows.30d'],
            ['key' => 'all', 'label_key' => 'admin.logs.statistics.windows.all'],
        ];
    }

    public function normalize(mixed $window): string
    {
        $candidate = is_string($window) ? $window : self::DEFAULT;
        $allowed = array_column($this->options(), 'key');

        return in_array($candidate, $allowed, true) ? $candidate : self::DEFAULT;
    }

    public function since(string $window, ?DateTimeImmutable $now = null): ?DateTimeImmutable
    {
        $now ??= new DateTimeImmutable();

        return match ($this->normalize($window)) {
            '1h' => $now->sub(new DateInterval('PT1H')),
            '24h' => $now->sub(new DateInterval('P1D')),
            '7d' => $now->sub(new DateInterval('P7D')),
            '30d' => $now->sub(new DateInterval('P30D')),
            'all' => null,
        };
    }
}
