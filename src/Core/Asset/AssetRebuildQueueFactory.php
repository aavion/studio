<?php

declare(strict_types=1);

namespace App\Core\Asset;

use App\Core\Operation\ActionQueue;
use App\Core\Operation\Filesystem\RemovePathAction;
use App\Core\Operation\Process\RunCommandAction;
use App\Core\Package\PackageAssetSyncAction;
use App\Core\Package\PackageAssetSyncPackage;
use App\Core\Package\PackageAssetSyncer;

final readonly class AssetRebuildQueueFactory
{
    public function __construct(
        private string $projectDir,
        private PackageAssetSyncer $packageAssetSyncer,
    ) {
    }

    /**
     * @param list<PackageAssetSyncPackage> $packages
     */
    public function create(string $environment, array $packages): ActionQueue
    {
        $isProduction = 'prod' === $environment;
        $actions = [
            new PackageAssetSyncAction($this->packageAssetSyncer, $packages),
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
            'package_count' => count($packages),
            'production_compile' => $isProduction,
        ]);
    }

    private function consoleCommand(string $command, string $environment, ?float $timeout = 120.0): RunCommandAction
    {
        return new RunCommandAction([
            PHP_BINARY,
            $this->projectDir.'/bin/console',
            $command,
            '--env='.$environment,
            '--no-interaction',
        ], $this->projectDir, timeout: $timeout);
    }
}
