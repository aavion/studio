<?php

declare(strict_types=1);

namespace App\Core\Operation\Process;

use App\Core\DryRun\DryRunAction;
use App\Core\DryRun\DryRunRisk;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Operation\OperationActionInterface;
use App\Core\Workflow\OperationIssue;
use App\Core\Workflow\OperationResult;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

final readonly class RunCommandAction implements OperationActionInterface
{
    /**
     * @param list<string> $command
     * @param array<string, string|false> $env
     */
    public function __construct(
        private array $command,
        private ?string $cwd = null,
        private array $env = [],
        private ?float $timeout = 60.0,
        private int $excerptLength = 2000,
    ) {
        if ([] === $command) {
            throw new InvalidArgumentException('Command action command must not be empty.');
        }

        foreach ($command as $part) {
            if (!is_string($part) || '' === trim($part)) {
                throw new InvalidArgumentException('Command action command parts must contain non-empty strings.');
            }
        }

        foreach ($env as $name => $value) {
            if (!is_string($name) || '' === trim($name)) {
                throw new InvalidArgumentException('Command action environment names must be non-empty strings.');
            }

            if (!is_string($value) && false !== $value) {
                throw new InvalidArgumentException('Command action environment values must be strings or false.');
            }
        }

        if ($excerptLength < 0) {
            throw new InvalidArgumentException('Command action excerpt length must not be negative.');
        }
    }

    public function type(): string
    {
        return 'run_command';
    }

    public function label(): string
    {
        return sprintf('Run command %s', $this->formatCommand());
    }

    public function dryRun(): DryRunAction
    {
        return DryRunAction::create($this->type(), $this->label(), DryRunRisk::Medium, context: [
            'command' => $this->command,
            'command_line' => $this->formatCommand(),
            'cwd' => $this->cwd,
            'env_keys' => array_keys($this->env),
            'timeout' => $this->timeout,
        ]);
    }

    /**
     * @return OperationResult<array{exit_code: int|null, output_excerpt: string, error_excerpt: string}>
     */
    public function execute(): OperationResult
    {
        $process = new Process($this->command, $this->cwd, $this->env, null, $this->timeout);
        $process->run();

        $context = [
            'command' => $this->command,
            'command_line' => $this->formatCommand(),
            'cwd' => $this->cwd,
            'exit_code' => $process->getExitCode(),
            'output_excerpt' => $this->excerpt($process->getOutput()),
            'error_excerpt' => $this->excerpt($process->getErrorOutput()),
        ];

        if (!$process->isSuccessful()) {
            return OperationResult::failed([
                OperationIssue::create(MessageCode::PROCESS_COMMAND_FAILED, MessageKey::PROCESS_COMMAND_FAILED, [
                    '%command%' => $this->formatCommand(),
                    '%exit_code%' => $process->getExitCode() ?? 'unknown',
                ], $context, MessageLevel::Error),
            ], $context);
        }

        return OperationResult::success([
            'exit_code' => $process->getExitCode(),
            'output_excerpt' => $context['output_excerpt'],
            'error_excerpt' => $context['error_excerpt'],
        ], $context, [
            Message::info(MessageCode::PROCESS_COMMAND_COMPLETED, MessageKey::PROCESS_COMMAND_COMPLETED, [
                '%command%' => $this->formatCommand(),
                '%exit_code%' => $process->getExitCode() ?? 'unknown',
            ], $context),
        ]);
    }

    private function formatCommand(): string
    {
        return implode(' ', array_map('escapeshellarg', $this->command));
    }

    private function excerpt(string $output): string
    {
        if (strlen($output) <= $this->excerptLength) {
            return $output;
        }

        if (0 === $this->excerptLength) {
            return '';
        }

        return substr($output, 0, $this->excerptLength);
    }
}
