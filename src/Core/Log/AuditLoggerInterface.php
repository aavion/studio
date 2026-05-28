<?php

declare(strict_types=1);

namespace App\Core\Log;

use App\Core\Access\AccessActor;

interface AuditLoggerInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function log(AccessActor $actor, string $action, array $context = []): void;
}
