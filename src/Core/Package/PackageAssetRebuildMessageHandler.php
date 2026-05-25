<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Workflow\OperationResult;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PackageAssetRebuildMessageHandler
{
    public function __construct(private PackageLifecycleAssetRebuilderInterface $assetRebuilder)
    {
    }

    public function __invoke(PackageAssetRebuildMessage $message): OperationResult
    {
        return $this->assetRebuilder->rebuild($message->environment());
    }
}
