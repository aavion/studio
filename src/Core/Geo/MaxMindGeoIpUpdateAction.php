<?php

declare(strict_types=1);

namespace App\Core\Geo;

use App\Core\DryRun\DryRunAction;
use App\Core\DryRun\DryRunRisk;
use App\Core\Operation\OperationActionInterface;
use App\Core\Workflow\WorkflowResult;

final readonly class MaxMindGeoIpUpdateAction implements OperationActionInterface
{
    public function __construct(
        private MaxMindGeoIpDatabaseUpdater $updater,
        private string $trigger,
    ) {
    }

    public function type(): string
    {
        return 'geoip_database_update';
    }

    public function label(): string
    {
        return 'Update GeoIP2 database';
    }

    public function dryRun(): DryRunAction
    {
        return DryRunAction::create($this->type(), $this->label(), DryRunRisk::Medium, context: [
            'trigger' => $this->trigger,
        ]);
    }

    public function execute(): WorkflowResult
    {
        return $this->updater->update($this->trigger);
    }
}
