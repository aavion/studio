<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\DryRun\DryRunAction;
use App\Core\DryRun\DryRunRisk;
use App\Core\Operation\OperationActionInterface;
use App\Core\Workflow\WorkflowResult;

final readonly class ExtensionAssetSyncAction implements OperationActionInterface
{
    /**
     * @param list<ExtensionAssetSyncTarget> $extensions
     */
    public function __construct(
        private ExtensionAssetSyncer $syncer,
        private array $extensions,
    ) {
    }

    public function type(): string
    {
        return 'extension_asset_sync';
    }

    public function label(): string
    {
        return 'Synchronize active extension assets';
    }

    public function dryRun(): DryRunAction
    {
        return DryRunAction::create($this->type(), $this->label(), DryRunRisk::Medium, [
            'assets/extensions',
            'assets/styles/extensions',
            'assets/js/extensions',
        ], context: [
            'extensions' => array_map(static fn (ExtensionAssetSyncTarget $extension): string => $extension->identifier(), $this->extensions),
        ]);
    }

    /**
     * @return WorkflowResult<array{extensions: int, mirrored_assets: int, css_entries: int, javascript_entries: int, tailwind_sources: int}>
     */
    public function execute(): WorkflowResult
    {
        return $this->syncer->sync($this->extensions);
    }
}
