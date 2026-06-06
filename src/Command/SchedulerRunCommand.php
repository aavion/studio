<?php

declare(strict_types=1);

namespace App\Command;

use App\Core\Console\ConsoleResultRenderer;
use App\Scheduler\SchedulerRunner;
use App\Scheduler\SchedulerTaskDefinition;
use App\Scheduler\SchedulerTaskRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'scheduler:run',
    description: 'Run due scheduler tasks.',
)]
final class SchedulerRunCommand extends Command
{
    public function __construct(
        private readonly SchedulerRunner $runner,
        private readonly SchedulerTaskRegistry $registry,
        private readonly ConsoleResultRenderer $resultRenderer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Return machine-readable JSON output.')
            ->addOption('job', null, InputOption::VALUE_REQUIRED, 'Run a specific scheduler task identifier even when it is not due.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $job = $input->getOption('job');
        $job = is_string($job) && '' !== trim($job) ? trim($job) : null;

        if (null !== $job && (!SchedulerTaskDefinition::isValidIdentifier($job) || null === $this->registry->definition($job))) {
            $output->writeln(sprintf('Unknown scheduler job "%s".', $job));

            return Command::FAILURE;
        }

        $result = $this->runner->run($job, null !== $job);
        $payload = $result->toArray();

        if ((bool) $input->getOption('json')) {
            $this->resultRenderer->writeJsonPayload($output, $payload);
        } else {
            $output->writeln(sprintf('Scheduler status: %s', $payload['status']));
            foreach ($payload['tasks'] as $task) {
                $output->writeln(sprintf('- %s: %s', $task['identifier'] ?? 'unknown', $task['status'] ?? 'unknown'));
            }
        }

        if (!in_array($payload['status'], ['completed', 'locked'], true)) {
            return Command::FAILURE;
        }

        if (null !== $job && [] === $payload['tasks']) {
            return Command::FAILURE;
        }

        foreach ($payload['tasks'] as $task) {
            if (($task['status'] ?? null) === 'failed') {
                return Command::FAILURE;
            }

            if (null !== $job && ($task['status'] ?? null) !== 'success') {
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }
}
