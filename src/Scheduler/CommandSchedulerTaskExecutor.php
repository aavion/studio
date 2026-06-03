<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Core\Operation\Process\PhpCliUnavailableAction;
use App\Core\Operation\Process\RunCommandAction;
use App\Core\Process\PhpCliBinaryResolver;
use App\Entity\SchedulerTask;

final readonly class CommandSchedulerTaskExecutor implements SchedulerTaskExecutorInterface
{
    public function __construct(
        private string $projectDir,
        private string $environment,
        private SchedulerCommandTargetParser $targetParser = new SchedulerCommandTargetParser(),
        private PhpCliBinaryResolver $phpCliBinaryResolver = new PhpCliBinaryResolver(),
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
        $resolution = $this->phpCliBinaryResolver->resolve($this->projectDir, [
            'APP_ENV' => $this->environment,
        ]);

        if (!$resolution->isAvailable()) {
            return SchedulerTaskExecution::failed([
                'task' => $task->identifier(),
                'target' => $task->target(),
                'php_cli_reason' => $resolution->reason(),
            ], [
                PhpCliUnavailableAction::message($task->target(), $resolution->reason(), [
                    'task' => $task->identifier(),
                    'target' => $task->target(),
                ]),
            ]);
        }

        $command = [...$resolution->commandPrefix(), $this->projectDir.'/bin/console', ...$parts];

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
}
