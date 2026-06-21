<?php

declare(strict_types=1);

namespace App\Core\Translation;

use App\Core\DryRun\DryRunAction;
use App\Core\DryRun\DryRunRisk;
use App\Core\Operation\OperationActionInterface;
use App\Core\Extension\ExtensionAssetSyncTarget;
use App\Core\Workflow\WorkflowResult;

final readonly class TranslationAggregateAction implements OperationActionInterface
{
    /**
     * @param list<ExtensionAssetSyncTarget> $extensions
     */
    public function __construct(
        private TranslationCatalogueAggregator $aggregator,
        private array $extensions,
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
            'extensions' => array_map(static fn (ExtensionAssetSyncTarget $extension): string => $extension->identifier(), $this->extensions),
        ]);
    }

    /**
     * @return WorkflowResult<array{extensions: int, locales: int, files: int, targets: list<string>}>
     */
    public function execute(): WorkflowResult
    {
        return $this->aggregator->aggregate($this->extensions);
    }
}
