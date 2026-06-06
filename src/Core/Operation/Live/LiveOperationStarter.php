<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Operation\OperationMessageKey;
use App\Core\Process\DetachedProcessStarter;
use App\Core\Process\PhpCliBinaryManager;
use App\Core\Workflow\WorkflowResult;
use App\Setup\SetupLiveOperationPayloadProtector;
use Symfony\Component\HttpKernel\KernelInterface;
use Throwable;

final readonly class LiveOperationStarter
{
    public function __construct(
        private KernelInterface $kernel,
        private LiveOperationRunStore $runStore,
        private SetupLiveOperationPayloadProtector $setupPayloadProtector,
        private PhpCliBinaryManager $phpCliBinaryManager,
        private DetachedProcessStarter $detachedProcessStarter,
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
                    CommonMessageCode::E_OPERATION_FAILED,
                    OperationMessageKey::OPERATION_START_FAILED,
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
                OperationMessageKey::OPERATION_STARTED,
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
        if (!$this->detachedProcessStarter->start(
            $command,
            $this->kernel->getProjectDir(),
            $this->runStore->outputPath($operationId),
            $this->runStore->pidPath($operationId),
            ['APP_ENV' => $this->kernel->getEnvironment()],
        )) {
            throw new \RuntimeException('Live operation runner could not be started.');
        }
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
