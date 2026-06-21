<?php

declare(strict_types=1);

namespace App\Backend;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Extension\ExtensionStatus;
use App\Core\Extension\ExtensionActivator;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Extension\ExtensionRemover;
use App\Core\Workflow\WorkflowResult;

final readonly class ExtensionLifecycleReviewProvider
{
    public function __construct(
        private ExtensionAdminDetailProvider $detailProvider,
        private ExtensionActivator $activator,
        private ExtensionRemover $remover,
    ) {
    }

    public function review(string $extensionName, string $action): array
    {
        $extension = $this->detailProvider->extension($extensionName);
        $plan = null;

        if (null !== $extension && !$extension['immutable']) {
            $plan = match ($action) {
                ExtensionLifecycleAdmin::ACTION_ACTIVATE => $this->activator->planActivation($extensionName)->toArray(),
                ExtensionLifecycleAdmin::ACTION_DEACTIVATE => $this->activator->planDeactivation($extensionName)->toArray(),
                ExtensionLifecycleAdmin::ACTION_RESET_FAULT => $this->faultResetPlan($extension)->toArray(),
                ExtensionLifecycleAdmin::ACTION_PURGE => $this->purgePlan($extension)->toArray(),
                ExtensionLifecycleAdmin::ACTION_DELETE => $this->remover->planRemoval($extensionName)->toArray(),
                default => null,
            };
        }

        return [
            'extension' => $extension,
            'action' => $action,
            'action_key' => str_replace('-', '_', $action),
            'plan' => $plan,
        ];
    }

    /**
     * @param array<string, mixed> $extension
     *
     * @return WorkflowResult<array<string, mixed>>
     */
    private function faultResetPlan(array $extension): WorkflowResult
    {
        return WorkflowResult::success([
            'extension' => $extension['extension_name'],
            'changes' => [[
                'extension' => $extension['extension_name'],
                'action' => 'fault_reset',
                'status' => ExtensionStatus::Inactive->value,
            ]],
            'asset_rebuild' => false,
        ]);
    }

    /**
     * @param array<string, mixed> $extension
     *
     * @return WorkflowResult<array<string, mixed>>
     */
    private function purgePlan(array $extension): WorkflowResult
    {
        if (ExtensionStatus::Removed->value !== ($extension['status'] ?? null)) {
            return WorkflowResult::blocked([
                Message::create(
                    ExtensionMessageCode::EXTENSION_LIFECYCLE_STATUS_BLOCKED,
                    ExtensionMessageKey::EXTENSION_LIFECYCLE_STATUS_BLOCKED,
                    ['%extension%' => $extension['extension_name'], '%status%' => (string) ($extension['status'] ?? 'unknown')],
                    ['extension' => $extension['extension_name'], 'status' => $extension['status'] ?? null, 'action' => ExtensionLifecycleAdmin::ACTION_PURGE],
                    MessageLevel::Warning,
                ),
            ]);
        }

        return WorkflowResult::success([
            'extension' => $extension['extension_name'],
            'changes' => [[
                'extension' => $extension['extension_name'],
                'action' => 'purged',
                'status' => 'deleted',
            ]],
            'asset_rebuild' => false,
        ]);
    }
}
