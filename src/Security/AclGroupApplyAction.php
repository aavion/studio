<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\DryRun\DryRunAction;
use App\Core\DryRun\DryRunRisk;
use App\Core\Operation\OperationActionInterface;
use App\Core\Workflow\WorkflowResult;

final readonly class AclGroupApplyAction implements OperationActionInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private AclGroupApplyService $applyService,
        private string $groupUid,
        private string $action,
        private string $actorUid,
        private array $payload = [],
    ) {
    }

    public function type(): string
    {
        return 'acl_group_apply';
    }

    public function label(): string
    {
        return match ($this->action) {
            AclGroupApplyService::ACTION_DELETE => 'Delete ACL group and clean references',
            AclGroupApplyService::ACTION_UPDATE => 'Apply ACL group update',
            default => 'Apply ACL group change',
        };
    }

    public function dryRun(): DryRunAction
    {
        return DryRunAction::create($this->type(), $this->label(), DryRunRisk::High, context: [
            'group_uid' => $this->groupUid,
            'action' => $this->action,
            'actor_uid' => $this->actorUid,
            'payload_keys' => array_keys($this->payload),
        ]);
    }

    /**
     * @return WorkflowResult<array<string, mixed>|null>
     */
    public function execute(): WorkflowResult
    {
        return $this->applyService->apply($this->groupUid, $this->action, $this->actorUid, $this->payload);
    }
}
