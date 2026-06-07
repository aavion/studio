<?php

declare(strict_types=1);

namespace App\Api\Security;

use App\Database\DatabaseReadyState;

final readonly class DatabaseApiAvailabilityChecker implements ApiAvailabilityCheckerInterface
{
    public function __construct(private DatabaseReadyState $databaseReadyState)
    {
    }

    public function isAvailable(): bool
    {
        return $this->databaseReadyState->isReady();
    }
}
