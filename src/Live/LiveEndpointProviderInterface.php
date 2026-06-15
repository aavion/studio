<?php

declare(strict_types=1);

namespace App\Live;

interface LiveEndpointProviderInterface
{
    /**
     * @return list<LiveEndpointDefinition>
     */
    public function liveEndpoints(): array;
}
