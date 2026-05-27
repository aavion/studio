<?php

declare(strict_types=1);

namespace App\Core\Log;

interface AuditLogPolicyInterface
{
    public function allows(string $action): bool;
}
