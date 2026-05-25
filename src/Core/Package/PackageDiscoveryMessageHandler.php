<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Workflow\OperationResult;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PackageDiscoveryMessageHandler
{
    public function __construct(private PackageDiscoveryRunner $runner)
    {
    }

    public function __invoke(PackageDiscoveryMessage $message): OperationResult
    {
        return ($this->runner)($message->trigger());
    }
}
