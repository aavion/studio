<?php

declare(strict_types=1);

namespace App\Command;

use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Operation\Live\LiveOperationQueueFactory;
use App\Core\Operation\Live\LiveOperationRunLock;
use App\Core\Operation\Live\LiveOperationRunStore;
use App\Core\Operation\OperationActionInterface;
use App\Core\Operation\OperationExecutor;
use App\Core\Operation\OperationMessageCode;
use App\Core\Operation\OperationMessageKey;
use App\Core\Workflow\WorkflowResult;
use App\Core\Workflow\WorkflowStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'operations:run',
    description: 'Run a staged live operation and write ActionLog progress.',
)]
final class LiveOperationRunCommand extends Command
{
    public function __construct(
        private readonly LiveOperationRunStore $runStore,
        private readonly LiveOperationQueueFactory $queueFactory,
        private readonly OperationExecutor $operationExecutor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('operation-id', InputArgument::REQUIRED, 'The staged operation id.')
            ->addOption('token', null, InputOption::VALUE_REQUIRED, 'The staged operation run token.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $operationId = (string) $input->getArgument('operation-id');
        $token = (string) $input->getOption('token');
        $state = $this->runStore->claimForRunner($operationId, $token);

        if (null === $state) {
            return Command::FAILURE;
        }

        $operation = (string) ($state['operation'] ?? '');
        $payload = is_array($state['payload'] ?? null) ? $state['payload'] : [];
        $lock = $this->runStore->acquireRunnerLock($operationId);

        if (null === $lock) {
            $result = WorkflowResult::failed([
                Message::warning(
                    CommonMessageCode::E_OPERATION_FAILED,
                    OperationMessageKey::OPERATION_LOCKED,
                    ['%operation%' => $operation],
                    ['operation' => $operation, 'operation_id' => $operationId],
                ),
            ], ['operation' => $operation, 'operation_id' => $operationId]);
            $this->runStore->finish($operationId, false, $result->toArray());
            $this->runStore->cleanup(3600);

            return Command::FAILURE;
        }

        try {
            $queueResult = $this->queueFactory->create($operation, $payload);

            if (!$queueResult->isSuccess() || null === $queueResult->value()) {
                $this->runStore->finish($operationId, false, $queueResult->toArray());

                return Command::FAILURE;
            }

            $queue = $queueResult->value();
            $this->runStore->setTotal($operationId, count($queue));
            $lock->touch();

            $execution = $this->operationExecutor->executeQueue(
                $queue,
                fn ($entry, int $index, int $total, WorkflowResult $result): null => $this->appendEntry($operationId, $entry, $index, $total, $lock),
                fn ($entry, int $index, int $total, OperationActionInterface $action): null => $this->appendEntry($operationId, $entry, $index, $total, $lock),
            );
            $this->runStore->finish($operationId, $execution->result()->isSuccess(), $execution->result()->toArray());

            return in_array($execution->result()->status(), [WorkflowStatus::Success, WorkflowStatus::RequiresReview], true)
                ? Command::SUCCESS
                : Command::FAILURE;
        } catch (Throwable $error) {
            $result = WorkflowResult::failed([
                Message::exception(
                    OperationMessageCode::OPERATION_EXCEPTION,
                    OperationMessageKey::OPERATION_EXCEPTION,
                    context: [
                        'operation' => $operation,
                        'operation_id' => $operationId,
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                    ],
                ),
            ], ['operation' => $operation, 'operation_id' => $operationId]);
            $this->runStore->finish($operationId, false, $result->toArray());

            return Command::FAILURE;
        } finally {
            $lock->release();
            $this->runStore->cleanup(3600);
        }
    }

    private function appendEntry(string $operationId, mixed $entry, int $index, int $total, LiveOperationRunLock $lock): null
    {
        $this->runStore->appendEntry($operationId, $entry, $index, $total);
        $lock->touch();

        return null;
    }
}
