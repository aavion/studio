<?php

declare(strict_types=1);

namespace App\Core\Console;

use App\Core\Workflow\WorkflowResult;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class ConsoleWorkflowResultRenderer
{
    /**
     * @param WorkflowResult<mixed> $result
     */
    public function write(OutputInterface $output, WorkflowResult $result): int
    {
        foreach ($result->issues() as $issue) {
            $output->writeln(sprintf('[%s] %s', $issue->level()->value, $issue->translationKey()));
        }

        foreach ($result->messages() as $message) {
            $output->writeln(sprintf('[%s] %s', $message->level()->value, $message->translationKey()));
        }

        return $this->exitCode($result);
    }

    /**
     * @param WorkflowResult<mixed> $result
     */
    public function exitCode(WorkflowResult $result): int
    {
        return $result->isSuccess() ? Command::SUCCESS : Command::FAILURE;
    }
}
