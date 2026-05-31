<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use App\Core\Operation\ActionQueue;
use App\Core\Workflow\WorkflowResult;
use App\Setup\SetupApplyAction;
use App\Setup\SetupRunner;
use App\Setup\SetupWebInputFactory;

final readonly class SetupApplyLiveOperationProvider implements LiveOperationQueueProviderInterface
{
    public function __construct(
        private SetupWebInputFactory $inputFactory,
        private SetupRunner $setupRunner,
    ) {
    }

    public function operation(): string
    {
        return LiveOperationQueueFactory::SETUP_APPLY;
    }

    public function create(array $payload = []): WorkflowResult
    {
        $values = is_array($payload['values'] ?? null) ? $payload['values'] : [];

        return WorkflowResult::success(ActionQueue::create('setup apply', [
            new SetupApplyAction($this->inputFactory, $this->setupRunner, $values),
        ], context: [
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
