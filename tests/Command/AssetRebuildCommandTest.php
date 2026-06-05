<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AssetRebuildCommand;
use App\Command\PackageAssetSyncCommand;
use App\Core\Asset\AssetRebuildQueueFactory;
use App\Core\Operation\OperationExecutor;
use App\Core\Package\ActivePackageAssetProviderInterface;
use App\Core\Package\PackageAssetRebuildDispatcher;
use App\Core\Package\PackageAssetRebuildMessage;
use App\Core\Package\PackageAssetSyncPackage;
use App\Core\Package\PackageAssetSyncer;
use App\Core\Package\PackageScope;
use App\Core\Translation\TranslationCatalogueAggregator;
use App\Tests\Support\FilesystemTestHelper;
use App\Tests\Support\NullWorkflowResultMessageReporter;
use App\Tests\Support\RecordingMessageBus;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

final class AssetRebuildCommandTest extends TestCase
{
    use FilesystemTestHelper;

    private string $root;

    protected function setUp(): void
    {
        $this->root = $this->createTemporaryDirectory('studio-asset-command');
        $this->writeTestFile($this->root, 'bin/console', "#!/usr/bin/env php\n<?php echo \"Studio test\";\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testAssetRebuildDryRunSurvivesMissingPackageStorage(): void
    {
        $command = new AssetRebuildCommand(
            $this->kernel('test'),
            new FailingPackageAssetProvider(),
            new AssetRebuildQueueFactory(
                $this->root,
                new PackageAssetSyncer($this->root),
                new TranslationCatalogueAggregator($this->root),
            ),
            new OperationExecutor(new NullWorkflowResultMessageReporter()),
            $this->assetRebuildDispatcher(),
        );
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--dry-run' => true, '--json' => true]);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('asset rebuild', $payload['name']);
        self::assertCount(6, $payload['actions']);
        self::assertSame(RuntimeException::class, $payload['context']['package_provider_error']['exception']);
        self::assertFileDoesNotExist($this->root.'/.env.test.local');
    }

    public function testPackageAssetSyncDoesNotMutateWhenPackageStorageIsUnavailable(): void
    {
        $command = new PackageAssetSyncCommand(
            new FailingPackageAssetProvider(),
            new PackageAssetSyncer($this->root),
            new OperationExecutor(new NullWorkflowResultMessageReporter()),
        );
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--json' => true]);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame('failed', $payload['status']);
        self::assertSame('package.asset_provider_failed', $payload['error']['code']);
        self::assertDirectoryDoesNotExist($this->root.'/assets/packages');
    }

    public function testPackageAssetSyncPrintsActionIssuesInTextMode(): void
    {
        $this->createUnsafePackageAssetRoot();
        $command = new PackageAssetSyncCommand(
            new StaticPackageAssetProvider([
                new PackageAssetSyncPackage('broken', 'packages/broken', [PackageScope::Module]),
            ]),
            new PackageAssetSyncer($this->root),
            new OperationExecutor(new NullWorkflowResultMessageReporter()),
        );
        $tester = new CommandTester($command);

        try {
            $exitCode = $tester->execute([]);
        } finally {
            $this->removeUnsafePackageAssetRoot();
        }

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('package.asset_sync_failed:message.package.asset_sync_failed', $this->compactConsoleDisplay($tester));
        self::assertStringContainsString('Package asset sync failed.', $tester->getDisplay());
    }

    public function testAssetRebuildPrintsActionIssuesInTextMode(): void
    {
        $this->createUnsafePackageAssetRoot();
        $command = new AssetRebuildCommand(
            $this->kernel('test'),
            new StaticPackageAssetProvider([
                new PackageAssetSyncPackage('broken', 'packages/broken', [PackageScope::Module]),
            ]),
            new AssetRebuildQueueFactory(
                $this->root,
                new PackageAssetSyncer($this->root),
                new TranslationCatalogueAggregator($this->root),
            ),
            new OperationExecutor(new NullWorkflowResultMessageReporter()),
            $this->assetRebuildDispatcher(),
        );
        $tester = new CommandTester($command);

        try {
            $exitCode = $tester->execute([]);
        } finally {
            $this->removeUnsafePackageAssetRoot();
        }

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('package.asset_sync_failed:message.package.asset_sync_failed', $this->compactConsoleDisplay($tester));
        self::assertStringContainsString('Asset rebuild failed.', $tester->getDisplay());
    }

    private function compactConsoleDisplay(CommandTester $tester): string
    {
        return preg_replace('/\s+/', '', $tester->getDisplay()) ?: '';
    }

    public function testAssetRebuildCanBeQueuedWithoutLoadingPackages(): void
    {
        $messageBus = new RecordingMessageBus();
        $command = new AssetRebuildCommand(
            $this->kernel('test'),
            new FailingPackageAssetProvider(),
            new AssetRebuildQueueFactory(
                $this->root,
                new PackageAssetSyncer($this->root),
                new TranslationCatalogueAggregator($this->root),
            ),
            new OperationExecutor(new NullWorkflowResultMessageReporter()),
            $this->assetRebuildDispatcher($messageBus),
        );
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--queue' => true, '--trigger' => 'setup', '--json' => true]);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('success', $payload['status']);
        self::assertCount(1, $messageBus->messages());
        self::assertInstanceOf(PackageAssetRebuildMessage::class, $messageBus->messages()[0]);
        self::assertSame('test', $messageBus->messages()[0]->environment());
        self::assertSame('setup', $messageBus->messages()[0]->trigger());
    }

    private function kernel(string $environment): KernelInterface
    {
        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getEnvironment')->willReturn($environment);

        return $kernel;
    }

    private function assetRebuildDispatcher(?RecordingMessageBus $messageBus = null): PackageAssetRebuildDispatcher
    {
        return new PackageAssetRebuildDispatcher(
            $messageBus ?? new RecordingMessageBus(),
            new NullWorkflowResultMessageReporter(),
        );
    }

    private function createUnsafePackageAssetRoot(): void
    {
        mkdir($this->root.'/packages/broken', 0777, true);
        mkdir($this->root.'/external-assets', 0777, true);
        $this->createSymlinkOrSkip($this->root.'/external-assets', $this->root.'/packages/broken/assets');
    }

    private function removeUnsafePackageAssetRoot(): void
    {
        if (is_link($this->root.'/packages/broken/assets')) {
            unlink($this->root.'/packages/broken/assets');
        }
    }
}

final readonly class FailingPackageAssetProvider implements ActivePackageAssetProviderInterface
{
    /**
     * @return list<PackageAssetSyncPackage>
     */
    public function packages(): array
    {
        throw new RuntimeException('Package storage is unavailable.');
    }
}

final readonly class StaticPackageAssetProvider implements ActivePackageAssetProviderInterface
{
    /**
     * @param list<PackageAssetSyncPackage> $packages
     */
    public function __construct(private array $packages)
    {
    }

    /**
     * @return list<PackageAssetSyncPackage>
     */
    public function packages(): array
    {
        return $this->packages;
    }
}
