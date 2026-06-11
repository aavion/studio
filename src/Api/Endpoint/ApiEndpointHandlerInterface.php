<?php

declare(strict_types=1);

namespace App\Api\Endpoint;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

interface ApiEndpointHandlerInterface
{
    public function apiEndpointHandlerKey(): string;

    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response;
}
