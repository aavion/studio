<?php

declare(strict_types=1);

namespace App\Core\Geo;

use App\Core\Workflow\WorkflowResult;

interface MaxMindGeoIpDownloadClientInterface
{
    /**
     * @return WorkflowResult<null>
     */
    public function download(string $url, string $targetPath): WorkflowResult;
}
