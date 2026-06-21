<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Workflow\WorkflowResult;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ExtensionAssetRebuildMessageHandler
{
    public function __construct(private ExtensionLifecycleAssetRebuilderInterface $assetRebuilder)
    {
    }

    public function __invoke(ExtensionAssetRebuildMessage $message): WorkflowResult
    {
        return $this->assetRebuilder->rebuild($message->environment());
    }
}
