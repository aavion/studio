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
                'studio:operations:cleanup',
                '*/15 * * * *',
            ),
            SchedulerTaskDefinition::command(
                'system.package_discovery',
                'admin.scheduler.tasks.package_discovery.label',
                'admin.scheduler.tasks.package_discovery.description',
                'studio:packages:discover --run-now',
                '0 */6 * * *',
            ),
            SchedulerTaskDefinition::command(
                'system.statistics_snapshot',
                'admin.scheduler.tasks.statistics_snapshot.label',
                'admin.scheduler.tasks.statistics_snapshot.description',
                'studio:statistics:snapshot',
                '*/15 * * * *',
            ),
            SchedulerTaskDefinition::command(
                'system.cache_clear',
                'admin.scheduler.tasks.cache_clear.label',
                'admin.scheduler.tasks.cache_clear.description',
                'cache:clear',
                '0 4 * * *',
            ),
        ];
    }
}
