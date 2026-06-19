<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Workflow\WorkflowResult;
use App\Entity\Extension;

final readonly class ExtensionRemovalPlanner
{
    public function __construct(
        private ExtensionLifecycleStore $store,
        private ExtensionActivator $activator,
    ) {
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    public function planRemoval(string $extensionName): WorkflowResult
    {
        $extension = $this->store->extension($extensionName);

        if (null === $extension) {
            return $this->extensionNotFound($extensionName);
        }

        $changes = $this->plannedRemovalChanges($extension);

        if (ExtensionStatus::Active === $extension->status()) {
            $plan = $this->activator->planDeactivation($extensionName);

            if (!$plan->isSuccess()) {
                return WorkflowResult::failed($plan->issues(), [
                    'extension' => $extensionName,
                    'path' => $extension->path(),
                    'plan_context' => $plan->context(),
                ], $plan->messages());
            }

            $planChanges = $plan->value()['changes'] ?? [];
            if (is_array($planChanges)) {
                $changes = array_values(array_filter(
                    $planChanges,
                    static fn (mixed $change): bool => is_array($change),
                ));
            }

            if (ExtensionStatus::Removed !== $extension->status()) {
                $changes[] = ['extension' => $extension->extensionName(), 'action' => 'removed', 'status' => ExtensionStatus::Removed->value];
            }
        }

        return WorkflowResult::success([
            'extension' => $extensionName,
            'changes' => $changes,
        ], [
            'extension' => $extensionName,
            'path' => $extension->path(),
        ]);
    }

    /**
     * @return list<array{extension: string, action: string, status: string}>
     */
    private function plannedRemovalChanges(Extension $extension): array
    {
        $changes = [];

        if (ExtensionStatus::Active === $extension->status()) {
            $changes[] = ['extension' => $extension->extensionName(), 'action' => 'deactivated', 'status' => ExtensionStatus::Inactive->value];
        }

        if (ExtensionStatus::Removed !== $extension->status()) {
            $changes[] = ['extension' => $extension->extensionName(), 'action' => 'removed', 'status' => ExtensionStatus::Removed->value];
        }

        return $changes;
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    private function extensionNotFound(string $extensionName): WorkflowResult
    {
        return WorkflowResult::invalid([
            Message::create(
                ExtensionMessageCode::EXTENSION_LIFECYCLE_EXTENSION_NOT_FOUND,
                ExtensionMessageKey::EXTENSION_LIFECYCLE_EXTENSION_NOT_FOUND,
                ['%extension%' => $extensionName],
                ['extension' => $extensionName],
                MessageLevel::Warning,
            ),
        ]);
    }
}
