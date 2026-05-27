<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Workflow\WorkflowResult;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PackageAssetRebuildMessageHandler
{
    public function __construct(private PackageLifecycleAssetRebuilderInterface $assetRebuilder)
    {
    }

    public function __invoke(PackageAssetRebuildMessage $message): WorkflowResult
    {
        return $this->assetRebuilder->rebuild($message->environment());
    }
}
