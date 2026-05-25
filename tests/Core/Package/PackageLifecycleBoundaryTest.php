<?php

declare(strict_types=1);

namespace App\Tests\Core\Package;

use App\Core\Event\EventHookDescriptor;
use App\Core\Event\EventHookMode;
use App\Core\Event\PublicHookFailedEvent;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Package\ActivePackageProvider;
use App\Core\Package\PackageAssetRebuildDispatcher;
use App\Core\Package\PackageAssetRebuildMessage;
use App\Core\Package\PackageAssetRebuildMessageHandler;
use App\Core\Package\PackageFaultResetter;
use App\Core\Package\PackageLifecycleCleanupRunnerInterface;
use App\Core\Package\PackageLifecycleAssetRebuilderInterface;
use App\Core\Package\PackagePhpLoader;
use App\Core\Package\PackageRemover;
use App\Core\Package\PackageRuntimeFailureHandler;
use App\Core\Package\PackageScope;
use App\Core\Workflow\OperationIssue;
use App\Core\Workflow\OperationResult;
use App\Entity\ExtensionPackage;
use App\Tests\Support\FilesystemTestHelper;
use App\Tests\Support\RecordingMessageBus;
use App\View\ViewContextEvent;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PackageLifecycleBoundaryTest extends KernelTestCase
{
    use FilesystemTestHelper;

    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private BoundaryPackageLifecycleAssetRebuilder $assetRebuilder;
    private RecordingPackageLifecycleCleanupRunner $cleanupRunner;
    private string $projectDir;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->assetRebuilder = new BoundaryPackageLifecycleAssetRebuilder();
        $this->cleanupRunner = new RecordingPackageLifecycleCleanupRunner();
        $this->projectDir = $this->createTemporaryDirectory('studio-package-lifecycle-boundary');
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        $this->removeDirectory($this->projectDir);

        parent::tearDown();
    }

    public function testActivePackageProviderReturnsOnlyActiveRealPackages(): void
    {
        $this->insertPackage('demo-module', ['module'], 'active');
        $this->insertPackage('demo-theme', ['frontend-theme'], 'active');
        $this->insertPackage('inactive-module', ['module'], 'inactive');
        $this->insertPackage('system-local', ['system-template'], 'active', path: '.');

        $provider = new ActivePackageProvider($this->entityManager);

        self::assertSame(['demo-module', 'demo-theme'], array_map(
            static fn (ExtensionPackage $package): string => $package->packageName(),
            $provider->packages(),
        ));
        self::assertSame(['demo-module'], array_map(
            static fn (ExtensionPackage $package): string => $package->packageName(),
            $provider->packages(PackageScope::Module),
        ));
        self::assertSame('demo-theme', $provider->package('demo-theme')?->packageName());
        self::assertNull($provider->package('inactive-module'));
    }

    public function testRuntimeHookFailureMarksIdentifiedActivePackageFaulty(): void
    {
        $this->insertPackage('demo-module', ['module'], 'active');

        $messageBus = new RecordingMessageBus();

        $result = (new PackageRuntimeFailureHandler(
            $this->entityManager,
            new PackageAssetRebuildDispatcher($messageBus),
            'test',
        ))->handleHookFailure(new PublicHookFailedEvent(
            new ViewContextEvent([]),
            new EventHookDescriptor(ViewContextEvent::class, 'view', EventHookMode::Extend, MessageKey::EVENT_HOOK_VIEW_CONTEXT_SUMMARY, mutable: true),
            OperationIssue::create(MessageCode::EVENT_HOOK_LISTENER_FAILED, MessageKey::EVENT_HOOK_LISTENER_FAILED, ['%event%' => ViewContextEvent::class]),
            new RuntimeException('listener failed'),
            ['route' => 'demo'],
            'demo-module',
        ));

        self::assertTrue($result->isSuccess());
        self::assertTrue($result->value()['faulty']);
        self::assertSame('faulty', $this->packageStatus('demo-module'));
        self::assertSame(ViewContextEvent::class, $this->metadata('demo-module')['runtime_failure']['hook']);
        self::assertSame('faulty', $this->metadata('demo-module')['registry_state']);
        self::assertCount(1, $messageBus->messages());
        self::assertInstanceOf(PackageAssetRebuildMessage::class, $messageBus->messages()[0]);
        self::assertSame('test', $messageBus->messages()[0]->environment());
        self::assertSame('package_runtime_failure', $messageBus->messages()[0]->trigger());
    }

    public function testPackagePhpLoaderIncludesOnlyActivePackageLoaders(): void
    {
        $this->insertPackage('demo-module', ['module'], 'active');
        $this->insertPackage('inactive-module', ['module'], 'inactive');
        $this->writeTestFile($this->projectDir, 'packages/demo-module/package.php', <<<'PHP'
            <?php

            file_put_contents(__DIR__.'/loaded.txt', 'yes');

            return static function ($package): void {
                file_put_contents(__DIR__.'/called.txt', $package->packageName());
            };
            PHP);
        $this->writeTestFile($this->projectDir, 'packages/inactive-module/package.php', <<<'PHP'
            <?php

            file_put_contents(__DIR__.'/loaded.txt', 'no');
            PHP);

        $result = (new PackagePhpLoader(
            new ActivePackageProvider($this->entityManager),
            $this->entityManager,
            $this->projectDir,
        ))->loadActivePackages();

        self::assertTrue($result->isSuccess());
        self::assertSame(['demo-module'], $result->value()['loaded']);
        self::assertFileExists($this->projectDir.'/packages/demo-module/loaded.txt');
        self::assertSame('demo-module', file_get_contents($this->projectDir.'/packages/demo-module/called.txt'));
        self::assertFileDoesNotExist($this->projectDir.'/packages/inactive-module/loaded.txt');
    }

    public function testPackagePhpLoaderMarksFailingPackagesFaulty(): void
    {
        $this->insertPackage('broken-module', ['module'], 'active');
        $messageBus = new RecordingMessageBus();
        $this->writeTestFile($this->projectDir, 'packages/broken-module/package.php', <<<'PHP'
            <?php

            throw new RuntimeException('broken package loader');
            PHP);

        $result = (new PackagePhpLoader(
            new ActivePackageProvider($this->entityManager),
            $this->entityManager,
            $this->projectDir,
            new PackageAssetRebuildDispatcher($messageBus),
            'test',
        ))->loadActivePackages();

        self::assertFalse($result->isSuccess());
        self::assertSame('package.lifecycle.php_load_failed', $result->firstIssue()?->code());
        self::assertSame('faulty', $this->packageStatus('broken-module'));
        self::assertSame('RuntimeException', $this->metadata('broken-module')['runtime_loader']['exception']);
        self::assertCount(1, $messageBus->messages());
        self::assertInstanceOf(PackageAssetRebuildMessage::class, $messageBus->messages()[0]);
        self::assertSame('package_php_loader_faulty', $messageBus->messages()[0]->trigger());
    }

    public function testPackageAssetRebuildMessageHandlerRunsLifecycleRebuild(): void
    {
        $result = (new PackageAssetRebuildMessageHandler($this->assetRebuilder))(new PackageAssetRebuildMessage('test', 'faulty'));

        self::assertTrue($result->isSuccess());
        self::assertSame(['test'], $this->assetRebuilder->environments);
    }

    public function testPackageRemoverDeletesPackageDirectoryAndMarksRegistryRemoved(): void
    {
        $this->insertPackage('demo-module', ['module'], 'active');
        $this->writeTestFile($this->projectDir, 'packages/demo-module/.manifest', 'PACKAGE_NAME=Demo');
        $this->writeTestFile($this->projectDir, 'packages/demo-module/src/Demo.php', '<?php');

        $result = (new PackageRemover(
            $this->entityManager,
            $this->assetRebuilder,
            $this->cleanupRunner,
            $this->projectDir,
        ))->remove('demo-module', 'test');

        self::assertTrue($result->isSuccess());
        self::assertDirectoryDoesNotExist($this->projectDir.'/packages/demo-module');
        self::assertSame('removed', $this->packageStatus('demo-module'));
        self::assertSame(['test'], $this->assetRebuilder->environments);
        self::assertSame([], $this->cleanupRunner->packages);
        self::assertSame('removed', $this->metadata('demo-module')['registry_state']);
        self::assertSame(['demo-module', 'demo-module'], array_column($result->value()['changes'], 'package'));
    }

    public function testPackageRemoverPurgeRunsCleanupAndDeletesRegistryRow(): void
    {
        $this->insertPackage('demo-module', ['module'], 'removed');

        $result = (new PackageRemover(
            $this->entityManager,
            $this->assetRebuilder,
            $this->cleanupRunner,
            $this->projectDir,
        ))->purge('demo-module');

        self::assertTrue($result->isSuccess());
        self::assertSame(['demo-module'], $this->cleanupRunner->packages);
        self::assertFalse($this->packageRowExists('demo-module'));
        self::assertSame('purged', $result->value()['changes'][0]['action']);
    }

    public function testPackageFaultResetterReturnsValidatedFaultyPackageToInactive(): void
    {
        $this->insertPackage('demo-module', ['module'], 'faulty');
        $this->writePackageManifest('demo-module');

        $result = (new PackageFaultResetter(
            $this->entityManager,
            $this->projectDir,
            'test',
        ))->resetFault('demo-module');

        self::assertTrue($result->isSuccess());
        self::assertSame('inactive', $this->packageStatus('demo-module'));
        self::assertSame('fault_reset', $result->value()['changes'][0]['action']);
        self::assertSame('package.lifecycle.fault_reset', $result->messages()[1]->code());
        self::assertSame('available', $this->metadata('demo-module')['registry_state']);
        self::assertArrayHasKey('last_fault', $this->metadata('demo-module'));
    }

    public function testPackageFaultResetterKeepsInvalidPackageFaulty(): void
    {
        $this->insertPackage('demo-module', ['module'], 'faulty');
        $this->writePackageManifest('demo-module');
        $this->writeTestFile($this->projectDir, 'packages/demo-module/src/Broken.php', <<<'PHP'
            <?php

            final class Broken {
            PHP);

        $result = (new PackageFaultResetter(
            $this->entityManager,
            $this->projectDir,
            'test',
        ))->resetFault('demo-module');

        self::assertFalse($result->isSuccess());
        self::assertSame('package.php_syntax_error', $result->firstIssue()?->code());
        self::assertSame('faulty', $this->packageStatus('demo-module'));
    }

    public function testPackageFaultResetterBlocksNonFaultyPackage(): void
    {
        $this->insertPackage('demo-module', ['module'], 'inactive');
        $this->writePackageManifest('demo-module');

        $result = (new PackageFaultResetter(
            $this->entityManager,
            $this->projectDir,
            'test',
        ))->resetFault('demo-module');

        self::assertFalse($result->isSuccess());
        self::assertSame('package.lifecycle.status_blocked', $result->firstIssue()?->code());
        self::assertSame('inactive', $this->packageStatus('demo-module'));
    }

    /**
     * @param list<string> $scopes
     */
    private function insertPackage(
        string $packageName,
        array $scopes,
        string $status,
        string $path = '',
    ): void {
        $this->connection->insert('extension_package', [
            'uid' => $this->uuid(),
            'package_scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
            'package_name' => $packageName,
            'path' => '' === $path ? 'packages/'.$packageName : $path,
            'manifest_version' => '1.0.0',
            'installed_version' => '1.0.0',
            'status' => $status,
            'metadata' => json_encode([
                'registry_state' => 'available',
                'manifest' => [
                    'PACKAGE_DEPENDENCIES' => '[]',
                ],
            ], JSON_THROW_ON_ERROR),
            'modified_at' => '2026-05-25 00:00:00',
        ]);
    }

    private function packageStatus(string $packageName): string
    {
        $status = $this->connection->fetchOne(
            'SELECT status FROM extension_package WHERE package_name = :package_name',
            ['package_name' => $packageName],
        );

        self::assertIsString($status);

        return $status;
    }

    private function writePackageManifest(string $packageName): void
    {
        $this->writeTestFile($this->projectDir, 'packages/'.$packageName.'/.manifest', <<<'MANIFEST'
            PACKAGE_AUTHOR=Aavion Test Fixtures
            PACKAGE_NAME=Demo Module
            PACKAGE_VERSION=1.0.1
            PACKAGE_SCOPE=module
            PACKAGE_DEPENDENCIES=[]
            MANIFEST);
    }

    private function packageRowExists(string $packageName): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM extension_package WHERE package_name = :package_name',
            ['package_name' => $packageName],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(string $packageName): array
    {
        $metadata = $this->connection->fetchOne(
            'SELECT metadata FROM extension_package WHERE package_name = :package_name',
            ['package_name' => $packageName],
        );

        self::assertIsString($metadata);
        $decoded = json_decode($metadata, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

final class RecordingPackageLifecycleCleanupRunner implements PackageLifecycleCleanupRunnerInterface
{
    /**
     * @var list<string>
     */
    public array $packages = [];

    public function cleanup(ExtensionPackage $package): OperationResult
    {
        $this->packages[] = $package->packageName();

        return OperationResult::success([
            'package' => $package->packageName(),
            'actions' => [],
        ], [
            'package' => $package->packageName(),
            'actions' => [],
        ]);
    }
}

final class BoundaryPackageLifecycleAssetRebuilder implements PackageLifecycleAssetRebuilderInterface
{
    /**
     * @var list<string>
     */
    public array $environments = [];

    public function rebuild(string $environment): OperationResult
    {
        $this->environments[] = $environment;

        return OperationResult::success(context: ['fake' => true]);
    }
}
