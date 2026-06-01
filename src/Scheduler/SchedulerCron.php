<?php

declare(strict_types=1);

namespace App\Scheduler;

use Cron\CronExpression;
use DateTimeImmutable;

final readonly class SchedulerCron
{
    public static function nextRun(string $expression, ?DateTimeImmutable $from = null): DateTimeImmutable
    {
        $from ??= new DateTimeImmutable();

        return DateTimeImmutable::createFromMutable(CronExpression::factory($expression)->getNextRunDate($from));
    }

    public static function isValid(string $expression): bool
    {
        return CronExpression::isValidExpression($expression);
    }
}
