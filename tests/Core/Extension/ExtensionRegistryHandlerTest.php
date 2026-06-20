<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Message\Message;
use App\Core\Extension\ExtensionStatus;
use App\Core\Extension\ExtensionCandidate;
use App\Core\Extension\ExtensionDiscovery;
use App\Core\Extension\ExtensionLifecycleAssetRebuilderInterface;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Extension\ExtensionRegistryHandler;
use App\Core\Extension\ExtensionSource;
use App\Core\Workflow\WorkflowResult;
use App\Tests\Support\FilesystemTestHelper;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ExtensionRegistryHandlerTest extends KernelTestCase
{
    use FilesystemTestHelper;

    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private string $projectDir;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->projectDir = $this->createTemporaryDirectory('system-extension-registry');
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->connection->beginTransaction();
        $this->resetExtensionRegistry();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        $this->removeDirectory($this->projectDir);

        parent::tearDown();
    }

    public function testItRegistersNewExtensionsAsInactive(): void
    {
        $this->writeExtensionManifest('demo-module', '1.0.0');

        $result = $this->handler()->synchronize($this->candidates());

        self::assertTrue($result->isSuccess());
        $this->assertChangeRecorded($result->value(), 'demo-module', 'registered', 'inactive');

        $row = $this->extensionRow('demo-module');
        self::assertSame('inactive', $row['status']);
        self::assertSame('extensions/demo-module', $row['path']);
        self::assertSame('1.0.0', $row['manifest_version']);
        self::assertSame('1.0.0', $row['installed_version']);
        self::assertSame(['module'], json_decode((string) $row['extension_scopes'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('Demo Module', $this->metadata($row)['display_name']);
        self::assertSame('Registry handler demo extension.', $this->metadata($row)['description']);
        self::assertSame('MIT', $this->metadata($row)['license']);
        self::assertSame('assets/preview.svg', $this->metadata($row)['image']);
        self::assertSame('Demo Module', $this->metadata($row)['variables']['ext.demo_module.name']['value']);
    }

    public function testItMarksMissingFilesystemExtensionsAsRemoved(): void
    {
        $this->insertExtension('missing-module', 'extensions/missing-module', '1.0.0', 'active');
        $assetRebuilder = new RegistryHandlerExtensionLifecycleAssetRebuilder();

        $result = $this->handler($assetRebuilder)->synchronize([]);

        self::assertTrue($result->isSuccess());
        $this->assertChangeRecorded($result->value(), 'missing-module', 'removed', 'removed');

        $row = $this->extensionRow('missing-module');
        self::assertSame('removed', $row['status']);
        self::assertSame('extensions/missing-module', $this->metadata($row)['removed_path']);
        self::assertSame(['test'], $assetRebuilder->environments);
    }

    public function testItDeactivatesActiveDependentsWhenExtensionIsMarkedRemoved(): void
    {
        $this->insertExtension('missing-module', 'extensions/missing-module', '1.0.0', 'active');
        $this->insertExtension(
            'dependent-module',
            'extensions/dependent-module',
            '1.0.0',
            'active',
            dependencies: '[["missing-module", "1.0.0"]]',
        );
        $this->writeExtensionManifest('dependent-module', '1.0.0', '[["missing-module", "1.0.0"]]');
        $assetRebuilder = new RegistryHandlerExtensionLifecycleAssetRebuilder();

        $result = $this->handler($assetRebuilder)->synchronize($this->candidates());

        self::assertTrue($result->isSuccess(), json_encode($result->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame('removed', $this->extensionRow('missing-module')['status']);
        self::assertSame('inactive', $this->extensionRow('dependent-module')['status']);
        self::assertContains([
            'extension' => 'dependent-module',
            'action' => 'deactivated',
            'status' => 'inactive',
        ], $result->value());
        self::assertSame(['test'], $assetRebuilder->environments);
    }

    public function testItDeactivatesStaleActiveDependentsWhenInactiveExtensionIsMarkedRemoved(): void
    {
        $this->insertExtension('missing-module', 'extensions/missing-module', '1.0.0', 'inactive');
        $this->insertExtension(
            'dependent-module',
            'extensions/dependent-module',
            '1.0.0',
            'active',
            dependencies: '[["missing-module", "1.0.0"]]',
        );
        $this->writeExtensionManifest('dependent-module', '1.0.0', '[["missing-module", "1.0.0"]]');
        $assetRebuilder = new RegistryHandlerExtensionLifecycleAssetRebuilder();

        $result = $this->handler($assetRebuilder)->synchronize($this->candidates());

        self::assertTrue($result->isSuccess(), json_encode($result->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame('removed', $this->extensionRow('missing-module')['status']);
        self::assertSame('inactive', $this->extensionRow('dependent-module')['status']);
        self::assertContains([
            'extension' => 'dependent-module',
            'action' => 'deactivated',
            'status' => 'inactive',
        ], $result->value());
        self::assertSame(['test'], $assetRebuilder->environments);
    }

    public function testItUpdatesVersionMismatches(): void
    {
        $this->insertExtension('demo-module', 'extensions/demo-module', '1.0.0', 'inactive', installedVersion: '1.0.0');
        $this->writeExtensionManifest('demo-module', '1.1.0');

        $result = $this->handler()->synchronize($this->candidates());

        self::assertTrue($result->isSuccess());
        $this->assertChangeRecorded($result->value(), 'demo-module', 'updated', 'inactive');

        $row = $this->extensionRow('demo-module');
        self::assertSame('1.1.0', $row['manifest_version']);
        self::assertSame('1.1.0', $row['installed_version']);
        self::assertSame('1.1.0', $this->metadata($row)['manifest']['EXTENSION_VERSION']);
    }

    public function testItRunsAssetRebuildWhenActiveExtensionUpdates(): void
    {
        $this->insertExtension('demo-module', 'extensions/demo-module', '1.0.0', 'active', installedVersion: '1.0.0');
        $this->writeExtensionManifest('demo-module', '1.1.0');
        $assetRebuilder = new RegistryHandlerExtensionLifecycleAssetRebuilder();

        $result = $this->handler(assetRebuilder: $assetRebuilder)->synchronize($this->candidates());

        self::assertTrue($result->isSuccess());
        $this->assertChangeRecorded($result->value(), 'demo-module', 'updated', 'active');
        self::assertSame('active', $this->extensionRow('demo-module')['status']);
        self::assertSame(['test'], $assetRebuilder->environments);
    }

    public function testItFailsWhenSynchronousAssetRebuildFails(): void
    {
        $this->insertExtension('demo-module', 'extensions/demo-module', '1.0.0', 'active', installedVersion: '1.0.0');
        $this->writeExtensionManifest('demo-module', '1.1.0');
        $assetRebuilder = new RegistryHandlerExtensionLifecycleAssetRebuilder(WorkflowResult::failed([
            Message::error(
                ExtensionMessageCode::EXTENSION_ASSET_SYNC_FAILED,
                ExtensionMessageKey::EXTENSION_ASSET_SYNC_FAILED,
                ['%message%' => 'rebuild failed'],
            ),
        ]));

        $result = $this->handler(assetRebuilder: $assetRebuilder)->synchronize($this->candidates());

        self::assertFalse($result->isSuccess());
        self::assertSame(['test'], $assetRebuilder->environments);
        self::assertTrue($result->context()['stale_risk']);
        self::assertSame('extension.asset_sync_failed', $result->firstIssue()?->code());
    }

    public function testItMarksValidationFailuresAsFaulty(): void
    {
        $this->writeExtensionManifest('broken-module', '1.0.0');
        $this->writeTestFile($this->projectDir, 'extensions/broken-module/src/Broken.php', '<?php broken');

        $result = $this->handler()->synchronize($this->candidates());

        self::assertTrue($result->isSuccess());
        $this->assertChangeRecorded($result->value(), 'broken-module', 'faulty', 'faulty');

        $row = $this->extensionRow('broken-module');
        $metadata = $this->metadata($row);
        self::assertSame('faulty', $row['status']);
        self::assertSame('faulty', $metadata['registry_state']);
        self::assertSame(1, $metadata['validation']['issue_count']);
        self::assertSame('extension.php_syntax_error', $metadata['validation']['issues'][0]['code']);
    }

    public function testItRunsAssetRebuildWhenActiveExtensionBecomesFaulty(): void
    {
        $this->insertExtension('broken-module', 'extensions/broken-module', '1.0.0', 'active');
        $this->writeExtensionManifest('broken-module', '1.0.0');
        $this->writeTestFile($this->projectDir, 'extensions/broken-module/src/Broken.php', '<?php broken');
        $assetRebuilder = new RegistryHandlerExtensionLifecycleAssetRebuilder();

        $result = $this->handler($assetRebuilder)->synchronize($this->candidates());

        self::assertTrue($result->isSuccess());
        self::assertSame('faulty', $this->extensionRow('broken-module')['status']);
        self::assertSame(['test'], $assetRebuilder->environments);
    }

    public function testItKeepsFaultyExtensionsFaultyWithoutVersionChange(): void
    {
        $this->insertExtension('broken-module', 'extensions/broken-module', '1.0.0', 'faulty');
        $this->writeExtensionManifest('broken-module', '1.0.0');

        $result = $this->handler()->synchronize($this->candidates());

        self::assertTrue($result->isSuccess());
        self::assertSame([], $this->changesForExtension($result->value(), 'broken-module'));
        self::assertSame('faulty', $this->extensionRow('broken-module')['status']);
    }

    public function testItRevalidatesFaultyExtensionsAfterVersionChange(): void
    {
        $this->insertExtension('broken-module', 'extensions/broken-module', '1.0.0', 'faulty');
        $this->writeExtensionManifest('broken-module', '1.1.0');

        $result = $this->handler()->synchronize($this->candidates());

        self::assertTrue($result->isSuccess());
        $this->assertChangeRecorded($result->value(), 'broken-module', 'updated', 'inactive');
        self::assertSame('inactive', $this->extensionRow('broken-module')['status']);
    }

    private function resetExtensionRegistry(): void
    {
        $this->connection->executeStatement('DELETE FROM extension_setting_entry');
        $this->connection->executeStatement('DELETE FROM extension');
    }

    /**
     * @param list<array{extension: string, action: string, status: string}> $changes
     */
    private function assertChangeRecorded(array $changes, string $extension, string $action, string $status): void
    {
        self::assertContains([
            'extension' => $extension,
            'action' => $action,
            'status' => $status,
        ], $changes);
    }

    /**
     * @param list<array{extension: string, action: string, status: string}> $changes
     *
     * @return list<array{extension: string, action: string, status: string}>
     */
    private function changesForExtension(array $changes, string $extension): array
    {
        return array_values(array_filter(
            $changes,
            static fn (array $change): bool => $change['extension'] === $extension,
        ));
    }

    private function handler(?ExtensionLifecycleAssetRebuilderInterface $assetRebuilder = null): ExtensionRegistryHandler
    {
        return new ExtensionRegistryHandler(
            $this->entityManager,
            $this->projectDir,
            assetRebuilder: $assetRebuilder,
            environment: 'test',
        );
    }

    /**
     * @return list<ExtensionCandidate>
     */
    private function candidates(): array
    {
        $result = (new ExtensionDiscovery())->discoverSources($this->projectDir, [
            ExtensionSource::children('extension', 'extensions'),
        ]);

        self::assertTrue($result->isSuccess(), json_encode($result->toArray(), JSON_THROW_ON_ERROR));

        return $result->value();
    }

    private function writeExtensionManifest(string $slug, string $version, string $dependencies = '[]'): void
    {
        $this->writeTestFile($this->projectDir, 'extensions/'.$slug.'/.manifest', <<<MANIFEST
            EXTENSION_AUTHOR=Aavion
            EXTENSION_SLUG={$slug}
            EXTENSION_NAME=Demo Module
            EXTENSION_DESCRIPTION=Registry handler demo extension.
            EXTENSION_VERSION={$version}
            EXTENSION_SCOPE=module
            EXTENSION_DEPENDENCIES={$dependencies}
            EXTENSION_LICENSE=MIT
            EXTENSION_IMAGE=assets/preview.svg
            MANIFEST);
    }

    private function insertExtension(
        string $extensionName,
        string $path,
        string $version,
        string $status,
        string $dependencies = '[]',
        ?string $installedVersion = null,
    ): void
    {
        $this->connection->insert('extension', [
            'uid' => $this->uuid(),
            'extension_scopes' => json_encode(['module'], JSON_THROW_ON_ERROR),
            'extension_name' => $extensionName,
            'path' => $path,
            'manifest_version' => $version,
            'installed_version' => $installedVersion,
            'status' => $status,
            'metadata' => json_encode([
                'registry_state' => 'available',
                'manifest' => [
                    'EXTENSION_DEPENDENCIES' => $dependencies,
                ],
            ], JSON_THROW_ON_ERROR),
            'modified_at' => '2026-05-25 00:00:00',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function extensionRow(string $extensionName): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM extension WHERE extension_name = :extension_name',
            ['extension_name' => $extensionName],
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

final class RegistryHandlerExtensionLifecycleAssetRebuilder implements ExtensionLifecycleAssetRebuilderInterface
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
