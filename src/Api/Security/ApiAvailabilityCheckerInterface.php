<?php

declare(strict_types=1);

namespace App\Api\Security;

interface ApiAvailabilityCheckerInterface
{
    public function isAvailable(): bool;
}
