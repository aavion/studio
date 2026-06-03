<?php

declare(strict_types=1);

namespace App\Core\Asset;

use App\Core\Operation\ActionQueue;
use App\Core\Operation\OperationActionInterface;
use App\Core\Operation\Filesystem\RemovePathAction;
use App\Core\Operation\Process\PhpCliUnavailableAction;
use App\Core\Operation\Process\RunCommandAction;
use App\Core\Package\PackageAssetSyncAction;
use App\Core\Package\PackageAssetSyncPackage;
use App\Core\Package\PackageAssetSyncer;
use App\Core\Process\PhpCliBinaryResolver;
use App\Core\Translation\TranslationAggregateAction;
use App\Core\Translation\TranslationCatalogueAggregator;

final readonly class AssetRebuildQueueFactory
{
    public function __construct(
        private string $projectDir,
        private PackageAssetSyncer $packageAssetSyncer,
        private TranslationCatalogueAggregator $translationCatalogueAggregator,
        private PhpCliBinaryResolver $phpCliBinaryResolver = new PhpCliBinaryResolver(),
    ) {
    }

    /**
     * @param list<PackageAssetSyncPackage> $packages
     */
    public function create(string $environment, array $packages, string $trigger = 'manual'): ActionQueue
    {
        $isProduction = 'prod' === $environment;
        $actions = [
            new PackageAssetSyncAction($this->packageAssetSyncer, $packages),
            new TranslationAggregateAction($this->translationCatalogueAggregator, $packages),
            $this->consoleCommand('assets:install', $environment),
            $this->consoleCommand('importmap:install', $environment),
            $this->consoleCommand('tailwind:build', $environment, timeout: 300.0),
        ];

        if ($isProduction) {
            $actions[] = new RemovePathAction($this->projectDir, 'public/assets');
            $actions[] = $this->consoleCommand('asset-map:compile', $environment, timeout: 300.0);
        }

        $actions[] = $this->consoleCommand('cache:clear', $environment, timeout: 300.0);

        return ActionQueue::create('asset rebuild', $actions, context: [
            'environment' => $environment,
            'trigger' => '' === trim($trigger) ? 'manual' : trim($trigger),
            'package_count' => count($packages),
            'production_compile' => $isProduction,
        ]);
    }

    private function consoleCommand(string $command, string $environment, ?float $timeout = 120.0): OperationActionInterface
    {
        $resolution = $this->phpCliBinaryResolver->resolve($this->projectDir);

        if (!$resolution->isAvailable()) {
            return new PhpCliUnavailableAction($command, $resolution->reason(), [
                'environment' => $environment,
                'project_dir' => $this->projectDir,
            ]);
        }

        return new RunCommandAction([
            ...$resolution->commandPrefix(),
            $this->projectDir.'/bin/console',
            $command,
            '--env='.$environment,
            '--no-interaction',
        ], $this->projectDir, timeout: $timeout);
    }
}
