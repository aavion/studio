<?php

declare(strict_types=1);

namespace App\Core\Log;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

interface AccessLoggerInterface
{
    public function log(Request $request, Response $response): void;
}
