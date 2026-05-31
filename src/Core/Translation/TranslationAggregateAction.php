<?php

declare(strict_types=1);

namespace App\Core\Translation;

use App\Core\DryRun\DryRunAction;
use App\Core\DryRun\DryRunRisk;
use App\Core\Operation\OperationActionInterface;
use App\Core\Package\PackageAssetSyncPackage;
use App\Core\Workflow\WorkflowResult;

final readonly class TranslationAggregateAction implements OperationActionInterface
{
    /**
     * @param list<PackageAssetSyncPackage> $packages
     */
    public function __construct(
        private TranslationCatalogueAggregator $aggregator,
        private array $packages,
    ) {
    }

    public function type(): string
    {
        return 'translation_aggregate';
    }

    public function label(): string
    {
        return 'Aggregate translation catalogues';
    }

    public function dryRun(): DryRunAction
    {
        return DryRunAction::create($this->type(), $this->label(), DryRunRisk::Low, [
            'translations/runtime/{APP_ENV}/messages.*.yaml',
        ], context: [
            'packages' => array_map(static fn (PackageAssetSyncPackage $package): string => $package->identifier(), $this->packages),
        ]);
    }

    /**
     * @return WorkflowResult<array{packages: int, locales: int, files: int, targets: list<string>}>
     */
    public function execute(): WorkflowResult
    {
        return $this->aggregator->aggregate($this->packages);
    }
}
