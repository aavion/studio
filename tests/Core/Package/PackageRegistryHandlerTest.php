<?php

declare(strict_types=1);

namespace App\Tests\Core\Package;

use App\Core\Message\Message;
use App\Core\Package\ExtensionPackageStatus;
use App\Core\Package\PackageAssetRebuildDispatcher;
use App\Core\Package\PackageAssetRebuildMessage;
use App\Core\Package\PackageCandidate;
use App\Core\Package\PackageDiscovery;
use App\Core\Package\PackageLifecycleAssetRebuilderInterface;
use App\Core\Package\PackageMessageCode;
use App\Core\Package\PackageMessageKey;
use App\Core\Package\PackageRegistryHandler;
use App\Core\Package\PackageSource;
use App\Core\Workflow\WorkflowResult;
use App\Tests\Support\FilesystemTestHelper;
use App\Tests\Support\NullWorkflowResultMessageReporter;
use App\Tests\Support\RecordingMessageBus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class PackageRegistryHandlerTest extends KernelTestCase
{
    use FilesystemTestHelper;

    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private string $projectDir;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->projectDir = $this->createTemporaryDirectory('system-package-registry');
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
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

    public function testItRegistersNewPackagesAsInactive(): void
    {
        $this->writePackageManifest('demo-module', '1.0.0');

        $result = $this->handler()->synchronize($this->candidates());

        self::assertTrue($result->isSuccess());
        self::assertSame([[
            'package' => 'demo-module',
            'action' => 'registered',
            'status' => 'inactive',
        ]], $result->value());

        $row = $this->packageRow('demo-module');
        self::assertSame('inactive', $row['status']);
        self::assertSame('packages/demo-module', $row['path']);
        self::assertSame('1.0.0', $row['manifest_version']);
        self::assertSame('1.0.0', $row['installed_version']);
        self::assertSame(['module'], json_decode((string) $row['package_scopes'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('Demo Module', $this->metadata($row)['display_name']);
        self::assertSame('Registry handler demo package.', $this->metadata($row)['description']);
        self::assertSame('MIT', $this->metadata($row)['license']);
        self::assertSame('assets/preview.svg', $this->metadata($row)['image']);
    }

    public function testItMarksMissingFilesystemPackagesAsRemoved(): void
    {
        $this->insertPackage('missing-module', 'packages/missing-module', '1.0.0', 'active');
        $messageBus = new RecordingMessageBus();

        $result = $this->handler($messageBus)->synchronize([]);

        self::assertTrue($result->isSuccess());
        self::assertSame([[
            'package' => 'missing-module',
            'action' => 'removed',
            'status' => 'removed',
        ]], $result->value());

        $row = $this->packageRow('missing-module');
        self::assertSame('removed', $row['status']);
        self::assertSame('packages/missing-module', $this->metadata($row)['removed_path']);
        self::assertCount(1, $messageBus->messages());
        self::assertInstanceOf(PackageAssetRebuildMessage::class, $messageBus->messages()[0]);
        self::assertSame('package_registry_state_exit', $messageBus->messages()[0]->trigger());
    }

    public function testItDeactivatesActiveDependentsWhenPackageIsMarkedRemoved(): void
    {
        $this->insertPackage('missing-module', 'packages/missing-module', '1.0.0', 'active');
        $this->insertPackage(
            'dependent-module',
            'packages/dependent-module',
            '1.0.0',
            'active',
            dependencies: '[["missing-module", "1.0.0"]]',
        );
        $this->writePackageManifest('dependent-module', '1.0.0', '[["missing-module", "1.0.0"]]');
        $messageBus = new RecordingMessageBus();

        $result = $this->handler($messageBus)->synchronize($this->candidates());

        self::assertTrue($result->isSuccess(), json_encode($result->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame('removed', $this->packageRow('missing-module')['status']);
        self::assertSame('inactive', $this->packageRow('dependent-module')['status']);
        self::assertContains([
            'package' => 'dependent-module',
            'action' => 'deactivated',
            'status' => 'inactive',
        ], $result->value());
        self::assertCount(1, $messageBus->messages());
    }

    public function testItUpdatesVersionMismatches(): void
    {
        $this->insertPackage('demo-module', 'packages/demo-module', '1.0.0', 'inactive', installedVersion: '1.0.0');
        $this->writePackageManifest('demo-module', '1.1.0');

        $result = $this->handler()->synchronize($this->candidates());

        self::assertTrue($result->isSuccess());
        self::assertSame([[
            'package' => 'demo-module',
            'action' => 'updated',
            'status' => 'inactive',
        ]], $result->value());

        $row = $this->packageRow('demo-module');
        self::assertSame('1.1.0', $row['manifest_version']);
        self::assertSame('1.1.0', $row['installed_version']);
        self::assertSame('1.1.0', $this->metadata($row)['manifest']['PACKAGE_VERSION']);
    }

    public function testItQueuesAssetRebuildWhenActivePackageUpdates(): void
    {
        $this->insertPackage('demo-module', 'packages/demo-module', '1.0.0', 'active', installedVersion: '1.0.0');
        $this->writePackageManifest('demo-module', '1.1.0');
        $messageBus = new RecordingMessageBus();

        $result = $this->handler($messageBus)->synchronize($this->candidates());

        self::assertTrue($result->isSuccess());
        self::assertSame([[
            'package' => 'demo-module',
            'action' => 'updated',
            'status' => 'active',
        ]], $result->value());
        self::assertSame('active', $this->packageRow('demo-module')['status']);
        self::assertCount(1, $messageBus->messages());
        self::assertInstanceOf(PackageAssetRebuildMessage::class, $messageBus->messages()[0]);
        self::assertSame('package_registry_state_exit', $messageBus->messages()[0]->trigger());
    }

    public function testItFallsBackToSynchronousAssetRebuildWhenDeferredDispatchFails(): void
    {
        $this->insertPackage('demo-module', 'packages/demo-module', '1.0.0', 'active', installedVersion: '1.0.0');
        $this->writePackageManifest('demo-module', '1.1.0');
        $assetRebuilder = new RegistryHandlerPackageLifecycleAssetRebuilder();

        $result = $this->handler(new FailingRegistryHandlerMessageBus(), $assetRebuilder)->synchronize($this->candidates());

        self::assertTrue($result->isSuccess(), json_encode($result->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame(['test'], $assetRebuilder->environments);
        self::assertTrue($result->context()['asset_rebuild']['value']['fallback_completed']);
        self::assertFalse($result->context()['asset_rebuild']['context']['stale_risk']);
        self::assertContains('message.package.asset_rebuild_queue_failed', array_map(
            static fn ($message): string => $message->translationKey(),
            $result->messages(),
        ));
    }

    public function testItFailsWhenDeferredAndFallbackAssetRebuildsFail(): void
    {
        $this->insertPackage('demo-module', 'packages/demo-module', '1.0.0', 'active', installedVersion: '1.0.0');
        $this->writePackageManifest('demo-module', '1.1.0');
        $assetRebuilder = new RegistryHandlerPackageLifecycleAssetRebuilder(WorkflowResult::failed([
            Message::error(
                PackageMessageCode::PACKAGE_ASSET_SYNC_FAILED,
                PackageMessageKey::PACKAGE_ASSET_SYNC_FAILED,
                ['%message%' => 'fallback failed'],
            ),
        ]));

        $result = $this->handler(new FailingRegistryHandlerMessageBus(), $assetRebuilder)->synchronize($this->candidates());

        self::assertFalse($result->isSuccess());
        self::assertSame(['test'], $assetRebuilder->environments);
        self::assertFalse($result->context()['asset_rebuild']['value']['fallback_completed']);
        self::assertTrue($result->context()['asset_rebuild']['context']['stale_risk']);
        self::assertSame('package.asset_sync_failed', $result->firstIssue()?->code());
    }

    public function testItMarksValidationFailuresAsFaulty(): void
    {
        $this->writePackageManifest('broken-module', '1.0.0');
        $this->writeTestFile($this->projectDir, 'packages/broken-module/src/Broken.php', '<?php broken');

        $result = $this->handler()->synchronize($this->candidates());

        self::assertTrue($result->isSuccess());
        self::assertSame([[
            'package' => 'broken-module',
            'action' => 'faulty',
            'status' => 'faulty',
        ]], $result->value());

        $row = $this->packageRow('broken-module');
        $metadata = $this->metadata($row);
        self::assertSame('faulty', $row['status']);
        self::assertSame('faulty', $metadata['registry_state']);
        self::assertSame(1, $metadata['validation']['issue_count']);
        self::assertSame('package.php_syntax_error', $metadata['validation']['issues'][0]['code']);
    }

    public function testItQueuesAssetRebuildWhenActivePackageBecomesFaulty(): void
    {
        $this->insertPackage('broken-module', 'packages/broken-module', '1.0.0', 'active');
        $this->writePackageManifest('broken-module', '1.0.0');
        $this->writeTestFile($this->projectDir, 'packages/broken-module/src/Broken.php', '<?php broken');
        $messageBus = new RecordingMessageBus();

        $result = $this->handler($messageBus)->synchronize($this->candidates());

        self::assertTrue($result->isSuccess());
        self::assertSame('faulty', $this->packageRow('broken-module')['status']);
        self::assertCount(1, $messageBus->messages());
        self::assertInstanceOf(PackageAssetRebuildMessage::class, $messageBus->messages()[0]);
        self::assertSame('test', $messageBus->messages()[0]->environment());
        self::assertSame('package_registry_state_exit', $messageBus->messages()[0]->trigger());
    }

    public function testItKeepsFaultyPackagesFaultyWithoutVersionChange(): void
    {
        $this->insertPackage('broken-module', 'packages/broken-module', '1.0.0', 'faulty');
        $this->writePackageManifest('broken-module', '1.0.0');
        $messageBus = new RecordingMessageBus();

        $result = $this->handler($messageBus)->synchronize($this->candidates());

        self::assertTrue($result->isSuccess());
        self::assertSame([], $result->value());
        self::assertSame('faulty', $this->packageRow('broken-module')['status']);
        self::assertSame([], $messageBus->messages());
    }

    public function testItRevalidatesFaultyPackagesAfterVersionChange(): void
    {
        $this->insertPackage('broken-module', 'packages/broken-module', '1.0.0', 'faulty');
        $this->writePackageManifest('broken-module', '1.1.0');

        $result = $this->handler()->synchronize($this->candidates());

        self::assertTrue($result->isSuccess());
        self::assertSame([[
            'package' => 'broken-module',
            'action' => 'updated',
            'status' => 'inactive',
        ]], $result->value());
        self::assertSame('inactive', $this->packageRow('broken-module')['status']);
    }

    private function handler(?MessageBusInterface $messageBus = null, ?PackageLifecycleAssetRebuilderInterface $assetRebuilder = null): PackageRegistryHandler
    {
        return new PackageRegistryHandler(
            $this->entityManager,
            $this->projectDir,
            assetRebuildDispatcher: null === $messageBus ? null : new PackageAssetRebuildDispatcher($messageBus, new NullWorkflowResultMessageReporter()),
            assetRebuildFallback: $assetRebuilder,
            environment: 'test',
        );
    }

    /**
     * @return list<PackageCandidate>
     */
    private function candidates(): array
    {
        $result = (new PackageDiscovery())->discoverSources($this->projectDir, [
            PackageSource::children('package', 'packages'),
        ]);

        self::assertTrue($result->isSuccess(), json_encode($result->toArray(), JSON_THROW_ON_ERROR));

        return $result->value();
    }

    private function writePackageManifest(string $slug, string $version, string $dependencies = '[]'): void
    {
        $this->writeTestFile($this->projectDir, 'packages/'.$slug.'/.manifest', <<<MANIFEST
            PACKAGE_AUTHOR=Aavion
            PACKAGE_SLUG={$slug}
            PACKAGE_NAME=Demo Module
            PACKAGE_DESCRIPTION=Registry handler demo package.
            PACKAGE_VERSION={$version}
            PACKAGE_SCOPE=module
            PACKAGE_DEPENDENCIES={$dependencies}
            PACKAGE_LICENSE=MIT
            PACKAGE_IMAGE=assets/preview.svg
            MANIFEST);
    }

    private function insertPackage(
        string $packageName,
        string $path,
        string $version,
        string $status,
        string $dependencies = '[]',
        ?string $installedVersion = null,
    ): void
    {
        $this->connection->insert('extension_package', [
            'uid' => $this->uuid(),
            'package_scopes' => json_encode(['module'], JSON_THROW_ON_ERROR),
            'package_name' => $packageName,
            'path' => $path,
            'manifest_version' => $version,
            'installed_version' => $installedVersion,
            'status' => $status,
            'metadata' => json_encode([
                'registry_state' => 'available',
                'manifest' => [
                    'PACKAGE_DEPENDENCIES' => $dependencies,
                ],
            ], JSON_THROW_ON_ERROR),
            'modified_at' => '2026-05-25 00:00:00',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function packageRow(string $packageName): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM extension_package WHERE package_name = :package_name',
            ['package_name' => $packageName],
        );

        self::assertIsArray($row);

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function metadata(array $row): array
    {
        $metadata = json_decode((string) $row['metadata'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($metadata);

        return $metadata;
    }

    private function uuid(): string
    {
        return Uuid::v7()->toRfc4122();
    }
}

final class FailingRegistryHandlerMessageBus implements MessageBusInterface
{
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        throw new RuntimeException('queue unavailable');
    }
}

final class RegistryHandlerPackageLifecycleAssetRebuilder implements PackageLifecycleAssetRebuilderInterface
{
    /**
     * @var list<string>
     */
    public array $environments = [];

    /**
     * @param WorkflowResult<mixed>|null $result
     */
    public function __construct(private ?WorkflowResult $result = null)
    {
    }

    public function rebuild(string $environment): WorkflowResult
    {
        $this->environments[] = $environment;

        return $this->result ?? WorkflowResult::success(context: ['fallback' => true]);
    }
}
