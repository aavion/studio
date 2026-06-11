<?php

declare(strict_types=1);

namespace App\Api\Endpoint;

interface ApiEndpointProviderInterface
{
    /**
     * @return list<ApiEndpointDefinition>
     */
    public function apiEndpoints(): array;
}
