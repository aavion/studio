<?php

declare(strict_types=1);

namespace App\Core\Geo;

use App\Core\Workflow\WorkflowResult;

interface MaxMindGeoIpArchiveExtractorInterface
{
    /**
     * @return WorkflowResult<array{database_path: string}>
     */
    public function extractDatabase(string $archivePath, string $workspaceDir): WorkflowResult;
}
