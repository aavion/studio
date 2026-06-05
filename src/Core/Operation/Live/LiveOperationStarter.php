<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Process\CliProcessEnvironment;
use App\Core\Process\PhpCliBinaryManager;
use App\Core\Workflow\WorkflowResult;
use App\Setup\SetupLiveOperationPayloadProtector;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class LiveOperationStarter
{
    public function __construct(
        private KernelInterface $kernel,
        private LiveOperationRunStore $runStore,
        private SetupLiveOperationPayloadProtector $setupPayloadProtector,
        private PhpCliBinaryManager $phpCliBinaryManager,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return WorkflowResult<array{operation_id: string, token: string, operation: string, label: string, status: string}>
     */
    public function start(string $operation, array $payload, string $label): WorkflowResult
    {
        $run = null;

        try {
            if (LiveOperationQueueFactory::SETUP_APPLY === $operation) {
                $payload = $this->setupPayloadProtector->protect($payload);
            }

            $run = $this->runStore->create($operation, $payload, $label);
            $this->startProcess($run['operation_id'], $run['token']);
        } catch (Throwable $error) {
            $result = WorkflowResult::failed([
                Message::exception(
                    MessageCode::E_OPERATION_FAILED,
                    MessageKey::OPERATION_START_FAILED,
                    ['%operation%' => $operation],
                    [
                        'operation' => $operation,
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                    ],
                ),
            ], ['operation' => $operation]);

            if (is_array($run) && isset($run['operation_id'])) {
                $this->runStore->finish((string) $run['operation_id'], false, $result->toArray());
            }

            return $result;
        }

        return WorkflowResult::success($run, [
            'operation' => $operation,
            'operation_id' => $run['operation_id'],
        ], [
            Message::success(
                MessageKey::OPERATION_STARTED,
                ['%operation%' => $label],
                ['operation' => $operation, 'operation_id' => $run['operation_id']],
            ),
        ]);
    }

    private function startProcess(string $operationId, string $token): void
    {
        $command = [
            ...$this->phpCliCommandPrefix(),
            $this->kernel->getProjectDir().'/bin/console',
            'studio:operations:run',
            $operationId,
            '--token='.$token,
            '--env='.$this->kernel->getEnvironment(),
            '--no-interaction',
        ];
        $outputPath = $this->runStore->outputPath($operationId);
        $pidPath = $this->runStore->pidPath($operationId);
        if ('\\' === DIRECTORY_SEPARATOR) {
            $this->startWindowsProcess($command, $outputPath, $pidPath);

            return;
        }

        $shellCommand = implode(' ', array_map('escapeshellarg', $command))
            .' > '.escapeshellarg($outputPath).' 2>&1 & echo $! > '.escapeshellarg($pidPath);

        // Symfony Process stops async children on destruction, so we only use it
        // to ask the shell to detach the actual runner.
        $process = Process::fromShellCommandline(
            $shellCommand,
            $this->kernel->getProjectDir(),
            CliProcessEnvironment::fromCurrentProcess(['APP_ENV' => $this->kernel->getEnvironment()]),
            timeout: 5.0,
        );
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Live operation runner could not be started.');
        }
    }

    /**
     * @param list<string> $command
     */
    private function startWindowsProcess(array $command, string $outputPath, string $pidPath): void
    {
        if (false === file_put_contents($pidPath, 'started '.gmdate('c').PHP_EOL, LOCK_EX)) {
            throw new \RuntimeException('Live operation runner pid marker could not be written.');
        }

        $shellCommand = 'start "" /B '.implode(' ', array_map($this->windowsArgument(...), $command))
            .' > '.$this->windowsArgument($outputPath).' 2>&1';

        $process = Process::fromShellCommandline(
            'cmd /C '.$shellCommand,
            $this->kernel->getProjectDir(),
            CliProcessEnvironment::fromCurrentProcess(['APP_ENV' => $this->kernel->getEnvironment()]),
            timeout: 5.0,
        );
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Live operation runner could not be started.');
        }
    }

    private function windowsArgument(string $argument): string
    {
        return '"'.str_replace('"', '\"', $argument).'"';
    }

    /**
     * @return list<string>
     */
    private function phpCliCommandPrefix(): array
    {
        $resolution = $this->phpCliBinaryManager->resolve($this->kernel->getProjectDir(), $this->kernel->getEnvironment(), persistPreference: true);

        if (!$resolution->isAvailable()) {
            throw new \RuntimeException('PHP CLI binary could not be resolved: '.$resolution->reason().'.');
        }

        return $resolution->commandPrefix();
    }
}
