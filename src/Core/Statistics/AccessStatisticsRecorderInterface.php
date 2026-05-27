<?php

declare(strict_types=1);

namespace App\Core\Statistics;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

interface AccessStatisticsRecorderInterface
{
    public function record(Request $request, Response $response): void;
}
