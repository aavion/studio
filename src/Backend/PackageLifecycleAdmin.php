<?php

declare(strict_types=1);

namespace App\Backend;

use App\Core\Workflow\WorkflowResult;

final readonly class PackageLifecycleAdmin
{
    public const ACTION_ACTIVATE = 'activate';
    public const ACTION_DEACTIVATE = 'deactivate';
    public const ACTION_RESET_FAULT = 'reset-fault';
    public const ACTION_PURGE = 'purge';
    public const ACTION_DELETE = 'delete';

    public function __construct(
        private PackageAdminDetailProvider $detailProvider,
        private PackageLifecycleReviewProvider $reviewProvider,
        private PackageLifecycleActionHandler $actionHandler,
    ) {
    }

    public function package(string $packageName): ?array
    {
        return $this->detailProvider->package($packageName);
    }

    public function review(string $packageName, string $action): array
    {
        return $this->reviewProvider->review($packageName, $action);
    }

    /**
     * @return WorkflowResult<array<string, mixed>|null>
     */
    public function apply(string $packageName, string $action): WorkflowResult
    {
        return $this->actionHandler->apply($packageName, $action);
    }
}
