<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Core\Operation\Process\RunCommandAction;
use App\Entity\SchedulerTask;

final readonly class CommandSchedulerTaskExecutor implements SchedulerTaskExecutorInterface
{
    public function __construct(
        private string $projectDir,
        private string $environment,
        private SchedulerCommandTargetParser $targetParser = new SchedulerCommandTargetParser(),
    )
    {
    }

    public function supports(SchedulerTask $task): bool
    {
        return SchedulerTaskType::Command === $task->type();
    }

    public function execute(SchedulerTask $task): SchedulerTaskExecution
    {
        $parts = $this->targetParser->parse($task->target());
        $command = [$this->phpBinary(), $this->projectDir.'/bin/console', ...$parts];

        $result = (new RunCommandAction($command, $this->projectDir, [
            'APP_ENV' => $this->environment,
        ], null, label: 'Run scheduler command '.$task->identifier()))->execute();

        return $result->isSuccess()
            ? SchedulerTaskExecution::success($result->context(), $result->messages())
            : SchedulerTaskExecution::failed($result->context(), [
                ...$result->issues(),
                ...$result->messages(),
            ]);
    }

    private function phpBinary(): string
    {
        $binary = PHP_BINARY;

        return '' !== $binary ? $binary : 'php';
    }
}
