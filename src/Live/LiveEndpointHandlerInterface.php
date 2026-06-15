<?php

declare(strict_types=1);

namespace App\Live;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

interface LiveEndpointHandlerInterface
{
    public function liveEndpointHandlerKey(): string;

    public function handleLiveRequest(Request $request, LiveEndpointDefinition $endpoint): Response;
}
