<?php

declare(strict_types=1);

namespace App\Core\Geo;

use App\Scheduler\SchedulerCallableProviderInterface;
use App\Scheduler\SchedulerTaskDefinition;
use App\Scheduler\SchedulerTaskExecution;
use App\Scheduler\SchedulerTaskProviderInterface;
use App\Scheduler\SchedulerTaskType;

final readonly class MaxMindGeoIpSchedulerProvider implements SchedulerTaskProviderInterface, SchedulerCallableProviderInterface
{
    private const TASK_IDENTIFIER = 'system.geoip2_database_update';
    private const CALLABLE_TARGET = 'system.geoip2.database_update';

    public function __construct(private MaxMindGeoIpDatabaseUpdater $updater)
    {
    }

    /**
     * @return list<SchedulerTaskDefinition>
     */
    public function schedulerTasks(): array
    {
        return [
            new SchedulerTaskDefinition(
                self::TASK_IDENTIFIER,
                'admin.scheduler.tasks.geoip2_database_update.label',
                'admin.scheduler.tasks.geoip2_database_update.description',
                'system',
                SchedulerTaskType::Callable,
                self::CALLABLE_TARGET,
                '0 3 * * *',
                true,
            ),
        ];
    }

    public function schedulerCallable(string $target): ?callable
    {
        if (self::CALLABLE_TARGET !== $target) {
            return null;
        }

        return function (): SchedulerTaskExecution {
            $result = $this->updater->update('scheduler');
            $messages = [
                ...$result->issues(),
                ...$result->messages(),
            ];

            return $result->isSuccess()
                ? SchedulerTaskExecution::success($result->context(), $messages)
                : SchedulerTaskExecution::failed($result->context(), $messages);
        };
    }
}
