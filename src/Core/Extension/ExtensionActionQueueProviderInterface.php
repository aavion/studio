<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Operation\ActionQueue;

interface ExtensionActionQueueProviderInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function extensionActionQueue(string $target, array $payload = []): ?ActionQueue;
}
