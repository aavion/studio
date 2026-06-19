<?php

declare(strict_types=1);

namespace App\Backend;

use App\Core\Workflow\WorkflowResult;

final readonly class ExtensionLifecycleAdmin
{
    public const ACTION_ACTIVATE = 'activate';
    public const ACTION_DEACTIVATE = 'deactivate';
    public const ACTION_RESET_FAULT = 'reset-fault';
    public const ACTION_PURGE = 'purge';
    public const ACTION_DELETE = 'delete';

    public function __construct(
        private ExtensionAdminDetailProvider $detailProvider,
        private ExtensionLifecycleReviewProvider $reviewProvider,
        private ExtensionLifecycleActionHandler $actionHandler,
    ) {
    }

    public function extension(string $extensionName): ?array
    {
        return $this->detailProvider->extension($extensionName);
    }

    public function review(string $extensionName, string $action): array
    {
        return $this->reviewProvider->review($extensionName, $action);
    }

    /**
     * @return WorkflowResult<array<string, mixed>|null>
     */
    public function apply(string $extensionName, string $action): WorkflowResult
    {
        return $this->actionHandler->apply($extensionName, $action);
    }
}
