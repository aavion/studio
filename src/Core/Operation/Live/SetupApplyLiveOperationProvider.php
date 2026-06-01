<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use App\Core\Operation\ActionQueue;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Workflow\WorkflowResult;
use App\Setup\SetupRunner;
use App\Setup\SetupLiveOperationPayloadProtector;
use App\Setup\SetupWebInputFactory;

final readonly class SetupApplyLiveOperationProvider implements LiveOperationQueueProviderInterface
{
    public function __construct(
        private SetupWebInputFactory $inputFactory,
        private SetupRunner $setupRunner,
        private SetupLiveOperationPayloadProtector $payloadProtector,
    ) {
    }

    public function operation(): string
    {
        return LiveOperationQueueFactory::SETUP_APPLY;
    }

    public function create(array $payload = []): WorkflowResult
    {
        $payload = $this->payloadProtector->unprotect($payload);
        $values = is_array($payload['values'] ?? null) ? $payload['values'] : [];
        $input = $this->inputFactory->create($values);

        if (!$input->isValid() || null === $input->input()) {
            return WorkflowResult::invalid([
                Message::warning(
                    MessageCode::E_INVALID_ARGUMENT,
                    MessageKey::OPERATION_INVALID_PAYLOAD,
                    context: ['operation' => $this->operation(), 'fields' => array_keys($input->errors())],
                ),
            ], ['errors' => $input->errors()]);
        }

        $queue = $this->setupRunner->queue($input->input());

        if (!$queue->isSuccess() || !$queue->value() instanceof ActionQueue) {
            return $queue;
        }

        return WorkflowResult::success(ActionQueue::create('setup apply', $queue->value()->actions(), context: [
            ...$queue->value()->context(),
            'trigger' => $this->trigger($payload),
        ]));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function trigger(array $payload): string
    {
        $trigger = $payload['trigger'] ?? 'live_operation';

        return is_string($trigger) && '' !== trim($trigger) ? trim($trigger) : 'live_operation';
    }
}
