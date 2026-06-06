<?php

declare(strict_types=1);

namespace App\Scheduler;

final class SchedulerMessageKey
{
    public const SCHEDULER_RUN_COMPLETED = 'message.scheduler.run_completed';
    public const SCHEDULER_RUN_FAILED = 'message.scheduler.run_failed';
    public const SCHEDULER_TASK_FAILED = 'message.scheduler.task_failed';
    public const SCHEDULER_TASK_DISABLED = 'message.scheduler.task_disabled';
    public const SCHEDULER_TASK_INVALID_CRON_DISABLED = 'message.scheduler.task_invalid_cron_disabled';
    public const SCHEDULER_TASK_SOFT_BUDGET_EXCEEDED = 'message.scheduler.task_soft_budget_exceeded';
    public const SCHEDULER_TASK_DEFINITION_TARGET_INVALID = 'message.scheduler.task_definition.target_invalid';
    public const SCHEDULER_TASK_DEFINITION_CRON_EMPTY = 'message.scheduler.task_definition.cron_empty';
    public const SCHEDULER_TASK_DEFINITION_CRON_INVALID = 'message.scheduler.task_definition.cron_invalid';
    public const SCHEDULER_TASK_DEFINITION_METADATA_INVALID = 'message.scheduler.task_definition.metadata_invalid';
    public const SCHEDULER_TASK_DEFINITION_IDENTIFIER_INVALID = 'message.scheduler.task_definition.identifier_invalid';
    public const SCHEDULER_TASK_DEFINITION_SOURCE_INVALID = 'message.scheduler.task_definition.source_invalid';
    public const SCHEDULER_TASK_DEFINITION_TRANSLATION_KEY_INVALID = 'message.scheduler.task_definition.translation_key_invalid';
}
