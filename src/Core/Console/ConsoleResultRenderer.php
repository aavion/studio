<?php

declare(strict_types=1);

namespace App\Core\Console;

use App\Core\Workflow\WorkflowResult;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class ConsoleResultRenderer
{
    /**
     * @param WorkflowResult<mixed> $result
     *
     * @throws \JsonException
     */
    public function writeWorkflow(OutputInterface $output, WorkflowResult $result, bool $json = false): int
    {
        if ($json) {
            return $this->writePayload($output, $result->toArray(), $this->workflowExitCode($result), true);
        }

        foreach ($result->issues() as $issue) {
            $output->writeln(sprintf('[%s] %s', $issue->level()->value, $issue->translationKey()));
        }

        foreach ($result->messages() as $message) {
            $output->writeln(sprintf('[%s] %s', $message->level()->value, $message->translationKey()));
        }

        return $this->workflowExitCode($result);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws \JsonException
     */
    public function writePayload(OutputInterface $output, array $payload, int $exitCode = Command::SUCCESS, bool $pretty = false): int
    {
        $this->writeJsonPayload($output, $payload, $pretty);

        return $exitCode;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws \JsonException
     */
    public function writeJsonPayload(OutputInterface $output, array $payload, bool $pretty = false): void
    {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $output->writeln(json_encode($payload, $flags));
    }

    /**
     * @param WorkflowResult<mixed> $result
     */
    public function workflowExitCode(WorkflowResult $result): int
    {
        return $result->isSuccess() ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param list<string> $failureStatuses
     */
    public function statusExitCode(string $status, array $failureStatuses = ['failed']): int
    {
        return in_array($status, $failureStatuses, true) ? Command::FAILURE : Command::SUCCESS;
    }
}
