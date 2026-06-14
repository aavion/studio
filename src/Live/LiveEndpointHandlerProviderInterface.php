<?php

declare(strict_types=1);

namespace App\Live;

interface LiveEndpointHandlerProviderInterface
{
    /**
     * @return list<LiveEndpointHandlerInterface>
     */
    public function liveEndpointHandlers(): array;
}
