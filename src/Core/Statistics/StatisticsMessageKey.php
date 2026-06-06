<?php

declare(strict_types=1);

namespace App\Core\Statistics;

final class StatisticsMessageKey
{
    public const STATISTICS_RECORD_FAILED = 'message.statistics.record_failed';
    public const STATISTICS_AGGREGATE_FAILED = 'message.statistics.aggregate_failed';
    public const STATISTICS_SNAPSHOT_STORE_FAILED = 'message.statistics.snapshot_store_failed';
    public const STATISTICS_CLEANUP_FAILED = 'message.statistics.cleanup_failed';
    public const STATISTICS_TRACE_ID_INVALID = 'message.statistics.trace_id_invalid';
}
