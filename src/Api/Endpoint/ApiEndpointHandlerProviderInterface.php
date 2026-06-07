<?php

declare(strict_types=1);

namespace App\Api\Endpoint;

interface ApiEndpointHandlerProviderInterface
{
    /**
     * @return list<ApiEndpointHandlerInterface>
     */
    public function apiEndpointHandlers(): array;
}
