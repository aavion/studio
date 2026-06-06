<?php

declare(strict_types=1);

namespace App\Backend;

use App\Backend\BackendMessageCode;
use App\Backend\BackendMessageKey;
use App\Core\Message\Message;
use App\Core\Package\PackageActivator;
use App\Core\Package\PackageFaultResetter;
use App\Core\Package\PackageRemover;
use App\Core\Workflow\WorkflowResult;
use Symfony\Component\HttpKernel\KernelInterface;

final readonly class PackageLifecycleActionHandler
{
    public function __construct(
        private PackageActivator $activator,
        private PackageFaultResetter $faultResetter,
        private PackageRemover $remover,
        private KernelInterface $kernel,
    ) {
    }

    /**
     * @return WorkflowResult<array<string, mixed>|null>
     */
    public function apply(string $packageName, string $action): WorkflowResult
    {
        return match ($action) {
            PackageLifecycleAdmin::ACTION_ACTIVATE => $this->activator->activate($packageName, $this->kernel->getEnvironment()),
            PackageLifecycleAdmin::ACTION_DEACTIVATE => $this->activator->deactivate($packageName, $this->kernel->getEnvironment()),
            PackageLifecycleAdmin::ACTION_RESET_FAULT => $this->faultResetter->resetFault($packageName),
            PackageLifecycleAdmin::ACTION_PURGE => $this->remover->purge($packageName),
            PackageLifecycleAdmin::ACTION_DELETE => $this->remover->remove($packageName, $this->kernel->getEnvironment()),
            default => WorkflowResult::invalid([
                Message::warning(
                    BackendMessageCode::BACKEND_ACTION_UNKNOWN,
                    BackendMessageKey::BACKEND_ACTION_UNKNOWN,
                    ['%action%' => $action],
                    ['action' => $action, 'package' => $packageName],
                ),
            ]),
        };
    }
}
