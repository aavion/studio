<?php

declare(strict_types=1);

namespace App\Backend;

use App\Backend\BackendMessageCode;
use App\Backend\BackendMessageKey;
use App\Core\Message\Message;
use App\Core\Extension\ExtensionActivator;
use App\Core\Extension\ExtensionFaultResetter;
use App\Core\Extension\ExtensionRemover;
use App\Core\Workflow\WorkflowResult;
use Symfony\Component\HttpKernel\KernelInterface;

final readonly class ExtensionLifecycleActionHandler
{
    public function __construct(
        private ExtensionActivator $activator,
        private ExtensionFaultResetter $faultResetter,
        private ExtensionRemover $remover,
        private KernelInterface $kernel,
    ) {
    }

    /**
     * @return WorkflowResult<array<string, mixed>|null>
     */
    public function apply(string $extensionName, string $action): WorkflowResult
    {
        return match ($action) {
            ExtensionLifecycleAdmin::ACTION_ACTIVATE => $this->activator->activate($extensionName, $this->kernel->getEnvironment()),
            ExtensionLifecycleAdmin::ACTION_DEACTIVATE => $this->activator->deactivate($extensionName, $this->kernel->getEnvironment()),
            ExtensionLifecycleAdmin::ACTION_RESET_FAULT => $this->faultResetter->resetFault($extensionName),
            ExtensionLifecycleAdmin::ACTION_PURGE => $this->remover->purge($extensionName),
            ExtensionLifecycleAdmin::ACTION_DELETE => $this->remover->remove($extensionName, $this->kernel->getEnvironment()),
            default => WorkflowResult::invalid([
                Message::warning(
                    BackendMessageCode::BACKEND_ACTION_UNKNOWN,
                    BackendMessageKey::BACKEND_ACTION_UNKNOWN,
                    ['%action%' => $action],
                    ['action' => $action, 'extension' => $extensionName],
                ),
            ]),
        };
    }
}
