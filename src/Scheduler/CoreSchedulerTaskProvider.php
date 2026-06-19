<?php

declare(strict_types=1);

namespace App\Scheduler;

final readonly class CoreSchedulerTaskProvider implements SchedulerTaskProviderInterface
{
    /**
     * @return list<SchedulerTaskDefinition>
     */
    public function schedulerTasks(): array
    {
        return [
            SchedulerTaskDefinition::command(
                'system.live_operation_cleanup',
                'admin.scheduler.tasks.live_operation_cleanup.label',
                'admin.scheduler.tasks.live_operation_cleanup.description',
                'operations:cleanup',
                '*/15 * * * *',
            ),
            SchedulerTaskDefinition::command(
                'system.ui_alert_inbox_cleanup',
                'admin.scheduler.tasks.ui_alert_inbox_cleanup.label',
                'admin.scheduler.tasks.ui_alert_inbox_cleanup.description',
                'ui-alerts:cleanup-inbox',
                '23 * * * *',
            ),
            SchedulerTaskDefinition::command(
                'system.extension_discovery',
                'admin.scheduler.tasks.extension_discovery.label',
                'admin.scheduler.tasks.extension_discovery.description',
                'extensions:discover --run-now',
                '0 */6 * * *',
            ),
            SchedulerTaskDefinition::command(
                'system.statistics_snapshot',
                'admin.scheduler.tasks.statistics_snapshot.label',
                'admin.scheduler.tasks.statistics_snapshot.description',
                'statistics:snapshot',
                '*/15 * * * *',
            ),
            SchedulerTaskDefinition::command(
                'system.cache_clear',
                'admin.scheduler.tasks.cache_clear.label',
                'admin.scheduler.tasks.cache_clear.description',
                'cache:clear',
                '0 4 * * *',
            ),
            SchedulerTaskDefinition::command(
                'system.mercure_health',
                'admin.scheduler.tasks.mercure_health.label',
                'admin.scheduler.tasks.mercure_health.description',
                'mercure:health',
                '7 * * * *',
            ),
        ];
    }
}
