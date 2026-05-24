<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\DryRun\DryRunAction;
use App\Core\DryRun\DryRunRisk;
use App\Core\Operation\OperationActionInterface;
use App\Core\Workflow\OperationResult;

final readonly class PackageAssetSyncAction implements OperationActionInterface
{
    /**
     * @param list<PackageAssetSyncPackage> $packages
     */
    public function __construct(
        private PackageAssetSyncer $syncer,
        private array $packages,
    ) {
    }

    public function type(): string
    {
        return 'package_asset_sync';
    }

    public function label(): string
    {
        return 'Synchronize active package assets';
    }

    public function dryRun(): DryRunAction
    {
        return DryRunAction::create($this->type(), $this->label(), DryRunRisk::Medium, [
            'assets/packages',
            'assets/styles/packages',
            'assets/js/packages',
        ], context: [
            'packages' => array_map(static fn (PackageAssetSyncPackage $package): string => $package->identifier(), $this->packages),
        ]);
    }

    /**
     * @return OperationResult<array{packages: int, mirrored_assets: int, css_entries: int, javascript_entries: int, tailwind_sources: int}>
     */
    public function execute(): OperationResult
    {
        return $this->syncer->sync($this->packages);
    }
}
