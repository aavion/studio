<?php

declare(strict_types=1);

namespace App\Core\Statistics;

interface AccessStatisticsStoreInterface
{
    /**
     * @param array<string, mixed> $snapshot
     */
    public function saveLatest(array $snapshot): bool;

    /**
     * @return array<string, mixed>|null
     */
    public function latest(): ?array;
}
