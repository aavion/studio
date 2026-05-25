<?php

declare(strict_types=1);

namespace App\Tests\Core\Package;

use App\Core\Package\ExtensionPackageStatus;
use App\Core\Package\PackageAssetRebuildDispatcher;
use App\Core\Package\PackageAssetRebuildMessage;
use App\Core\Package\PackageCandidate;
use App\Core\Package\PackageDiscovery;
use App\Core\Package\PackageRegistryHandler;
use App\Core\Package\PackageSource;
use App\Tests\Support\FilesystemTestHelper;
use App\Tests\Support\RecordingMessageBus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use App\Tests\Support\NullWorkflowResultMessageReporter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PackageRegistryHandlerTest extends KernelTestCase
{
    use FilesystemTestHelper;

    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private string $projectDir;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->projectDir = $this->createTemporaryDirectory('studio-package-registry');
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
        self::assertSame(['module'], json_decode((string) $row['package_scopes'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('Demo Module', $this->metadata($row)['display_name']);
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

    public function testItUpdatesVersionMismatches(): void
    {
        $this->insertPackage('demo-module', 'packages/demo-module', '1.0.0', 'inactive');
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
        self::assertSame('1.1.0', $this->metadata($row)['manifest']['PACKAGE_VERSION']);
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

    private function handler(?RecordingMessageBus $messageBus = null): PackageRegistryHandler
    {
        return new PackageRegistryHandler(
            $this->entityManager,
            $this->projectDir,
            assetRebuildDispatcher: null === $messageBus ? null : new PackageAssetRebuildDispatcher($messageBus, new NullWorkflowResultMessageReporter()),
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

    private function writePackageManifest(string $slug, string $version): void
    {
        $this->writeTestFile($this->projectDir, 'packages/'.$slug.'/.manifest', <<<MANIFEST
            PACKAGE_AUTHOR=Aavion
            PACKAGE_NAME=Demo Module
            PACKAGE_VERSION={$version}
            PACKAGE_SCOPE=module
            PACKAGE_DEPENDENCIES=[]
            MANIFEST);
    }

    private function insertPackage(string $packageName, string $path, string $version, string $status): void
    {
        $this->connection->insert('extension_package', [
            'uid' => $this->uuid(),
            'package_scopes' => json_encode(['module'], JSON_THROW_ON_ERROR),
            'package_name' => $packageName,
            'path' => $path,
            'manifest_version' => $version,
            'installed_version' => null,
            'status' => $status,
            'metadata' => json_encode(['registry_state' => 'available'], JSON_THROW_ON_ERROR),
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
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
