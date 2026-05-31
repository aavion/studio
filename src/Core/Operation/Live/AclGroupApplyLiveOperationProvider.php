<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Operation\ActionQueue;
use App\Core\Workflow\WorkflowResult;
use App\Security\AclGroupApplyAction;
use App\Security\AclGroupApplyService;
use Symfony\Component\HttpKernel\KernelInterface;

final readonly class AclGroupApplyLiveOperationProvider implements LiveOperationQueueProviderInterface
{
    public function __construct(
        private KernelInterface $kernel,
        private AclGroupApplyService $applyService,
    ) {
    }

    public function operation(): string
    {
        return LiveOperationQueueFactory::ACL_GROUP_APPLY;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return WorkflowResult<ActionQueue>
     */
    public function create(array $payload = []): WorkflowResult
    {
        $groupUid = $payload['group_uid'] ?? null;
        $action = $payload['action'] ?? null;
        $actorUid = $payload['actor_uid'] ?? null;

        if (!is_string($groupUid) || '' === trim($groupUid) || !is_string($action) || '' === trim($action) || !is_string($actorUid) || '' === trim($actorUid)) {
            return WorkflowResult::invalid([
                Message::warning(
                    MessageCode::E_INVALID_ARGUMENT,
                    MessageKey::OPERATION_INVALID_PAYLOAD,
                    ['%operation%' => $this->operation()],
                    ['operation' => $this->operation(), 'payload_keys' => array_keys($payload)],
                ),
            ], ['operation' => $this->operation(), 'payload_keys' => array_keys($payload)]);
        }

        $actionPayload = $payload['payload'] ?? [];
        $actionPayload = is_array($actionPayload) ? $actionPayload : [];

        return WorkflowResult::success(ActionQueue::create('acl group apply', [
            new AclGroupApplyAction($this->applyService, trim($groupUid), trim($action), trim($actorUid), $actionPayload),
        ], context: [
            'operation' => $this->operation(),
            'group_uid' => trim($groupUid),
            'action' => trim($action),
            'actor_uid' => trim($actorUid),
            'environment' => $this->environment($payload),
            'trigger' => $this->trigger($payload),
        ]));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function environment(array $payload): string
    {
        $environment = $payload['environment'] ?? $this->kernel->getEnvironment();

        return is_string($environment) && '' !== trim($environment) ? trim($environment) : $this->kernel->getEnvironment();
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
