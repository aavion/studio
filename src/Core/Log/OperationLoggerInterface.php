<?php

declare(strict_types=1);

namespace App\Core\Log;

interface OperationLoggerInterface
{
    /**
     * @param array<string, mixed> $state
     */
    public function logFinished(array $state): void;
}
