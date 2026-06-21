<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Message\MessageException;
use App\Core\Operation\OperationMessageKey;
use App\Core\Process\DetachedProcessStarter;
use App\Core\Process\PhpCliBinaryManager;
use App\Core\Workflow\WorkflowResult;
use App\Setup\SetupLiveOperationPayloadProtector;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

final readonly class LiveOperationStarter
{
    public function __construct(
        private KernelInterface $kernel,
        private LiveOperationRunStore $runStore,
        private SetupLiveOperationPayloadProtector $setupPayloadProtector,
        private PhpCliBinaryManager $phpCliBinaryManager,
        private DetachedProcessStarter $detachedProcessStarter,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $labelParameters
     *
     * @return WorkflowResult<array{operation_id: string, token: string, operation: string, label: string, status: string}>
     */
    public function start(string $operation, array $payload, string $label, array $labelParameters = []): WorkflowResult
    {
        $run = null;
        $label = $this->translatedLabel($label, $labelParameters);

        try {
            if (LiveOperationQueueFactory::SETUP_APPLY === $operation) {
                $payload = $this->setupPayloadProtector->protect($payload);
            }

            $run = $this->runStore->create($operation, $payload, $label);
            $this->startProcess($operation, $run['operation_id'], $run['token']);
        } catch (Throwable $error) {
            $issue = $error instanceof MessageException
                ? $error->message()->withContext([
                    'operation' => $operation,
                    'exception' => $error::class,
                ])
                : Message::exception(
                    CommonMessageCode::E_OPERATION_FAILED,
                    OperationMessageKey::OPERATION_START_FAILED,
                    ['%operation%' => $operation],
                    [
                        'operation' => $operation,
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                    ],
                );
            $result = WorkflowResult::failed([
                $issue,
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

    /**
     * @param array<string, mixed> $labelParameters
     */
    private function translatedLabel(string $label, array $labelParameters): string
    {
        try {
            $translated = $this->translator->trans($label, $labelParameters);
        } catch (Throwable) {
            return $label;
        }

        return '' !== trim($translated) ? $translated : $label;
    }

    private function startProcess(string $operation, string $operationId, string $token): void
    {
        $command = [
            ...$this->phpCliCommandPrefix($operation),
            $this->kernel->getProjectDir().'/bin/console',
            'operations:run',
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
            throw MessageException::forMessage(
                CommonMessageCode::E_OPERATION_FAILED,
                OperationMessageKey::OPERATION_RUNNER_START_FAILED,
                ['%operation%' => $operation],
                ['operation' => $operation, 'operation_id' => $operationId],
            );
        }
    }

    /**
     * @return list<string>
     */
    private function phpCliCommandPrefix(string $operation): array
    {
        $resolution = $this->phpCliBinaryManager->resolve($this->kernel->getProjectDir(), $this->kernel->getEnvironment(), persistPreference: true);

        if (!$resolution->isAvailable()) {
            throw MessageException::forMessage(
                CommonMessageCode::E_OPERATION_FAILED,
                OperationMessageKey::OPERATION_PHP_CLI_UNAVAILABLE,
                ['%operation%' => $operation, '%reason%' => $resolution->reason()],
                [...$resolution->context(), 'operation' => $operation, 'reason' => $resolution->reason()],
            );
        }

        return $resolution->commandPrefix();
    }
}
