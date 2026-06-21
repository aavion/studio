<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Workflow\WorkflowResult;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ExtensionDiscoveryMessageHandler
{
    public function __construct(private ExtensionDiscoveryRunner $runner)
    {
    }

    public function __invoke(ExtensionDiscoveryMessage $message): WorkflowResult
    {
        return ($this->runner)($message->trigger());
    }
}
