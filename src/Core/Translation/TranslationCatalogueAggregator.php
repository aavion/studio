<?php

declare(strict_types=1);

namespace App\Core\Translation;

use App\Core\Filesystem\PathGuard;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Extension\ExtensionAssetSyncTarget;
use App\Core\Translation\TranslationMessageCode;
use App\Core\Translation\TranslationMessageKey;
use App\Core\Workflow\WorkflowResult;
use Throwable;

final readonly class TranslationCatalogueAggregator
{
    private TranslationRuntimePath $runtimePath;
    private TranslationSourceCollector $sourceCollector;
    private TranslationCatalogueMerger $catalogueMerger;
    private TranslationRuntimeWriter $runtimeWriter;

    public function __construct(
        private string $projectDir,
        PathGuard $pathGuard = new PathGuard(),
        ?TranslationRuntimePath $runtimePath = null,
        ?TranslationSourceCollector $sourceCollector = null,
        ?TranslationCatalogueMerger $catalogueMerger = null,
        ?TranslationRuntimeWriter $runtimeWriter = null,
    ) {
        $this->runtimePath = $runtimePath ?? TranslationRuntimePath::fromGlobals($projectDir);
        $this->sourceCollector = $sourceCollector ?? new TranslationSourceCollector($projectDir, $pathGuard);
        $this->catalogueMerger = $catalogueMerger ?? new TranslationCatalogueMerger();
        $this->runtimeWriter = $runtimeWriter ?? new TranslationRuntimeWriter($projectDir, $pathGuard, $this->runtimePath);
    }

    /**
     * @param iterable<ExtensionAssetSyncTarget> $extensions
     *
     * @return WorkflowResult<array{extensions: int, locales: int, files: int, targets: list<string>}>
     */
    public function aggregate(iterable $extensions): WorkflowResult
    {
        try {
            return $this->doAggregate($extensions);
        } catch (Throwable $error) {
            $context = [
                'exception' => $error::class,
                'message' => $error->getMessage(),
                'target_pattern' => $this->runtimePath->relativeCataloguePattern(),
            ];

            return WorkflowResult::failed([
                Message::exception(TranslationMessageCode::TRANSLATION_AGGREGATE_FAILED, TranslationMessageKey::TRANSLATION_AGGREGATE_FAILED, [
                    '%path%' => $this->runtimePath->relativeDirectory().'/messages.*.yaml',
                ], $context),
            ], $context);
        }
    }

    /**
     * @param iterable<ExtensionAssetSyncTarget> $extensions
     */
    public function sourceHash(iterable $extensions): string
    {
        $sources = $this->sourceCollector->sources($this->sourceCollector->sortedExtensions($extensions));
        $fingerprints = [];

        foreach ($sources as $source) {
            $fingerprints[] = implode("\0", [
                $source['locale'],
                $this->sourceCollector->relativeSourcePath($source['path']),
                (string) hash_file('sha256', $source['path']),
            ]);
        }

        return hash('sha256', implode("\n", $fingerprints));
    }

    /**
     * @param iterable<ExtensionAssetSyncTarget> $extensions
     *
     * @return WorkflowResult<array{extensions: int, locales: int, files: int, targets: list<string>}>
     */
    private function doAggregate(iterable $extensions): WorkflowResult
    {
        $extensions = $this->sourceCollector->sortedExtensions($extensions);
        ['catalogues' => $catalogues, 'files' => $files] = $this->catalogueMerger->mergeSources(
            $this->sourceCollector->sources($extensions),
        );
        $targets = $this->runtimeWriter->write($catalogues);

        $context = [
            'extensions' => count($extensions),
            'locales' => count($catalogues),
            'files' => $files,
            'targets' => $targets,
        ];

        return WorkflowResult::success($context, $context, [
            Message::create(TranslationMessageCode::TRANSLATION_AGGREGATE_COMPLETED, TranslationMessageKey::TRANSLATION_AGGREGATE_COMPLETED, [
                '%files%' => (string) $files,
                '%locales%' => (string) count($catalogues),
                '%extensions%' => (string) count($extensions),
            ], $context, MessageLevel::Success),
        ]);
    }
}
