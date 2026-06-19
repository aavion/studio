<?php

declare(strict_types=1);

namespace App\Core\Asset;

use App\Core\Operation\ActionQueue;
use App\Core\Operation\OperationActionInterface;
use App\Core\Operation\Filesystem\RemovePathAction;
use App\Core\Operation\Process\PhpCliUnavailableAction;
use App\Core\Operation\Process\RunCommandAction;
use App\Core\Extension\ExtensionAssetSyncAction;
use App\Core\Extension\ExtensionAssetSyncTarget;
use App\Core\Extension\ExtensionAssetSyncer;
use App\Core\Process\PhpCliBinaryManager;
use App\Core\Translation\TranslationAggregateAction;
use App\Core\Translation\TranslationCatalogueAggregator;

final readonly class AssetRebuildQueueFactory
{
    public function __construct(
        private string $projectDir,
        private ExtensionAssetSyncer $extensionAssetSyncer,
        private TranslationCatalogueAggregator $translationCatalogueAggregator,
        private PhpCliBinaryManager $phpCliBinaryManager = new PhpCliBinaryManager(),
    ) {
    }

    /**
     * @param list<ExtensionAssetSyncTarget> $extensions
     */
    public function create(string $environment, array $extensions, string $trigger = 'manual', bool $persistPhpBinaryPreference = true): ActionQueue
    {
        $isProduction = 'prod' === $environment;
        $actions = [
            new ExtensionAssetSyncAction($this->extensionAssetSyncer, $extensions),
            new TranslationAggregateAction($this->translationCatalogueAggregator, $extensions),
            $this->consoleCommand('assets:install', $environment, $persistPhpBinaryPreference),
            $this->consoleCommand('importmap:install', $environment, $persistPhpBinaryPreference),
            $this->consoleCommand('ux:translator:warm-cache', $environment, $persistPhpBinaryPreference),
            $this->consoleCommand('ux:icons:lock', $environment, $persistPhpBinaryPreference, failOnError: false),
            $this->consoleCommand('tailwind:build', $environment, $persistPhpBinaryPreference, timeout: 300.0),
        ];

        if ($isProduction) {
            $actions[] = new RemovePathAction($this->projectDir, 'public/assets');
            $actions[] = $this->consoleCommand('asset-map:compile', $environment, $persistPhpBinaryPreference, timeout: 300.0);
        }

        $actions[] = $this->consoleCommand('cache:clear', $environment, $persistPhpBinaryPreference, timeout: 300.0);

        return ActionQueue::create('asset rebuild', $actions, context: [
            'environment' => $environment,
            'trigger' => '' === trim($trigger) ? 'manual' : trim($trigger),
            'extension_count' => count($extensions),
            'production_compile' => $isProduction,
        ]);
    }

    private function consoleCommand(string $command, string $environment, bool $persistPhpBinaryPreference, ?float $timeout = 120.0, bool $failOnError = true): OperationActionInterface
    {
        $resolution = $this->phpCliBinaryManager->resolve($this->projectDir, $environment, persistPreference: $persistPhpBinaryPreference);

        if (!$resolution->isAvailable()) {
            return new PhpCliUnavailableAction($command, $resolution->reason(), [
                'environment' => $environment,
                'project_dir' => $this->projectDir,
            ]);
        }

        $consoleCommand = [
            ...$resolution->commandPrefix(),
            $this->projectDir.'/bin/console',
            $command,
            '--env='.$environment,
            '--no-interaction',
        ];

        if ('tailwind:build' === $command) {
            return new TailwindBuildAction($consoleCommand, $this->projectDir, $timeout);
        }

        return new RunCommandAction($consoleCommand, $this->projectDir, timeout: $timeout, failOnError: $failOnError);
    }
}
