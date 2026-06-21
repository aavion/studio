<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AssetRebuildCommand;
use App\Command\ExtensionAssetSyncCommand;
use App\Core\Asset\AssetRebuildQueueFactory;
use App\Core\Console\ConsoleResultRenderer;
use App\Core\Operation\OperationExecutor;
use App\Core\Extension\ActiveExtensionAssetProviderInterface;
use App\Core\Extension\ExtensionAssetRebuildDispatcher;
use App\Core\Extension\ExtensionAssetRebuildMessage;
use App\Core\Extension\ExtensionAssetSyncTarget;
use App\Core\Extension\ExtensionAssetSyncer;
use App\Core\Extension\ExtensionScope;
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
        $this->root = $this->createTemporaryDirectory('system-asset-command');
        $this->writeTestFile($this->root, 'bin/console', "#!/usr/bin/env php\n<?php echo \"Studio test\";\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testAssetRebuildDryRunSurvivesMissingExtensionStorage(): void
    {
        $command = new AssetRebuildCommand(
            $this->kernel('test'),
            new FailingExtensionAssetProvider(),
            new AssetRebuildQueueFactory(
                $this->root,
                new ExtensionAssetSyncer($this->root),
                new TranslationCatalogueAggregator($this->root),
            ),
            new OperationExecutor(new NullWorkflowResultMessageReporter()),
            $this->assetRebuildDispatcher(),
            new ConsoleResultRenderer(),
        );
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--dry-run' => true, '--json' => true]);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('asset rebuild', $payload['name']);
        self::assertCount(8, $payload['actions']);
        self::assertSame(RuntimeException::class, $payload['context']['extension_provider_error']['exception']);
        self::assertFileDoesNotExist($this->root.'/.env.test.local');
    }

    public function testExtensionAssetSyncDoesNotMutateWhenExtensionStorageIsUnavailable(): void
    {
        $command = new ExtensionAssetSyncCommand(
            new FailingExtensionAssetProvider(),
            new ExtensionAssetSyncer($this->root),
            new OperationExecutor(new NullWorkflowResultMessageReporter()),
            new ConsoleResultRenderer(),
        );
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--json' => true]);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame('failed', $payload['status']);
        self::assertSame('extension.asset_provider_failed', $payload['error']['code']);
        self::assertDirectoryDoesNotExist($this->root.'/assets/extensions');
    }

    public function testExtensionAssetSyncPrintsActionIssuesInTextMode(): void
    {
        $this->createUnsafeExtensionAssetRoot();
        $command = new ExtensionAssetSyncCommand(
            new StaticExtensionAssetProvider([
                new ExtensionAssetSyncTarget('broken', 'extensions/broken', [ExtensionScope::Module]),
            ]),
            new ExtensionAssetSyncer($this->root),
            new OperationExecutor(new NullWorkflowResultMessageReporter()),
            new ConsoleResultRenderer(),
        );
        $tester = new CommandTester($command);

        try {
            $exitCode = $tester->execute([]);
        } finally {
            $this->removeUnsafeExtensionAssetRoot();
        }

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('extension.asset_sync_failed:message.extension.asset_sync_failed', $this->compactConsoleDisplay($tester));
        self::assertStringContainsString('Extension asset sync failed.', $tester->getDisplay());
    }

    public function testAssetRebuildPrintsActionIssuesInTextMode(): void
    {
        $this->createUnsafeExtensionAssetRoot();
        $command = new AssetRebuildCommand(
            $this->kernel('test'),
            new StaticExtensionAssetProvider([
                new ExtensionAssetSyncTarget('broken', 'extensions/broken', [ExtensionScope::Module]),
            ]),
            new AssetRebuildQueueFactory(
                $this->root,
                new ExtensionAssetSyncer($this->root),
                new TranslationCatalogueAggregator($this->root),
            ),
            new OperationExecutor(new NullWorkflowResultMessageReporter()),
            $this->assetRebuildDispatcher(),
            new ConsoleResultRenderer(),
        );
        $tester = new CommandTester($command);

        try {
            $exitCode = $tester->execute([]);
        } finally {
            $this->removeUnsafeExtensionAssetRoot();
        }

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('extension.asset_sync_failed:message.extension.asset_sync_failed', $this->compactConsoleDisplay($tester));
        self::assertStringContainsString('Asset rebuild failed.', $tester->getDisplay());
    }

    private function compactConsoleDisplay(CommandTester $tester): string
    {
        return preg_replace('/\s+/', '', $tester->getDisplay()) ?: '';
    }

    public function testAssetRebuildCanBeQueuedWithoutLoadingExtensions(): void
    {
        $messageBus = new RecordingMessageBus();
        $command = new AssetRebuildCommand(
            $this->kernel('test'),
            new FailingExtensionAssetProvider(),
            new AssetRebuildQueueFactory(
                $this->root,
                new ExtensionAssetSyncer($this->root),
                new TranslationCatalogueAggregator($this->root),
            ),
            new OperationExecutor(new NullWorkflowResultMessageReporter()),
            $this->assetRebuildDispatcher($messageBus),
            new ConsoleResultRenderer(),
        );
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--queue' => true, '--trigger' => 'setup', '--json' => true]);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('success', $payload['status']);
        self::assertCount(1, $messageBus->messages());
        self::assertInstanceOf(ExtensionAssetRebuildMessage::class, $messageBus->messages()[0]);
        self::assertSame('test', $messageBus->messages()[0]->environment());
        self::assertSame('setup', $messageBus->messages()[0]->trigger());
    }

    private function kernel(string $environment): KernelInterface
    {
        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getEnvironment')->willReturn($environment);

        return $kernel;
    }

    private function assetRebuildDispatcher(?RecordingMessageBus $messageBus = null): ExtensionAssetRebuildDispatcher
    {
        return new ExtensionAssetRebuildDispatcher(
            $messageBus ?? new RecordingMessageBus(),
            new NullWorkflowResultMessageReporter(),
        );
    }

    private function createUnsafeExtensionAssetRoot(): void
    {
        mkdir($this->root.'/extensions/broken', 0777, true);
        mkdir($this->root.'/external-assets', 0777, true);
        $this->createSymlinkOrSkip($this->root.'/external-assets', $this->root.'/extensions/broken/assets');
    }

    private function removeUnsafeExtensionAssetRoot(): void
    {
        if (is_link($this->root.'/extensions/broken/assets')) {
            unlink($this->root.'/extensions/broken/assets');
        }
    }
}

final readonly class FailingExtensionAssetProvider implements ActiveExtensionAssetProviderInterface
{
    /**
     * @return list<ExtensionAssetSyncTarget>
     */
    public function extensions(): array
    {
        throw new RuntimeException('Extension storage is unavailable.');
    }
}

final readonly class StaticExtensionAssetProvider implements ActiveExtensionAssetProviderInterface
{
    /**
     * @param list<ExtensionAssetSyncTarget> $extensions
     */
    public function __construct(private array $extensions)
    {
    }

    /**
     * @return list<ExtensionAssetSyncTarget>
     */
    public function extensions(): array
    {
        return $this->extensions;
    }
}
