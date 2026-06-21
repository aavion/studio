<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\ExtensionDiscovery;
use App\Core\Extension\ExtensionDiscoveryMessage;
use App\Core\Extension\ExtensionDiscoveryMessageHandler;
use App\Core\Extension\ExtensionDiscoveryRunner;
use App\Core\Extension\ExtensionRegistryHandler;
use App\Tests\Support\FilesystemTestHelper;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use App\Tests\Support\NullWorkflowResultMessageReporter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ExtensionDiscoveryRunnerTest extends KernelTestCase
{
    use FilesystemTestHelper;

    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private string $projectDir;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->projectDir = $this->createTemporaryDirectory('system-extension-discovery-runner');
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

    public function testItDiscoversValidExtensionsAndSynchronizesTheRegistry(): void
    {
        $this->writeExtensionManifest('demo-module', 'module');

        $result = ($this->runner())('admin_refresh');

        self::assertTrue($result->isSuccess(), json_encode($result->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame('admin_refresh', $result->context()['trigger']);
        self::assertSame(1, $result->value()['candidate_count']);
        self::assertContains([
            'extension' => 'demo-module',
            'action' => 'registered',
            'status' => 'inactive',
        ], $result->value()['changes']);

        $row = $this->extensionRow('demo-module');
        self::assertSame('inactive', $row['status']);
        self::assertSame('extensions/demo-module', $row['path']);
    }

    public function testItDoesNotMutateTheRegistryWhenDiscoveryFails(): void
    {
        $this->insertExtension('known-module', 'extensions/known-module', 'active');
        $this->writeExtensionManifest('broken-module', 'unknown-scope');

        $result = ($this->runner())('manual');

        self::assertFalse($result->isSuccess());
        self::assertSame(0, $result->context()['candidate_count']);
        self::assertSame('active', $this->extensionRow('known-module')['status']);
    }

    public function testMessageHandlerRunsDiscoveryWithMessageTrigger(): void
    {
        $this->writeExtensionManifest('message-module', 'module');

        $result = (new ExtensionDiscoveryMessageHandler($this->runner()))(new ExtensionDiscoveryMessage('messenger'));

        self::assertTrue($result->isSuccess(), json_encode($result->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame('messenger', $result->context()['trigger']);
        self::assertSame('inactive', $this->extensionRow('message-module')['status']);
    }

    private function runner(): ExtensionDiscoveryRunner
    {
        return new ExtensionDiscoveryRunner(
            new ExtensionDiscovery(),
            new ExtensionRegistryHandler($this->entityManager, $this->projectDir),
            $this->projectDir,
            'test',
            new NullWorkflowResultMessageReporter(),
        );
    }

    private function resetExtensionRegistry(): void
    {
        $this->connection->executeStatement('DELETE FROM extension_setting_entry');
        $this->connection->executeStatement('DELETE FROM extension');
    }

    private function writeExtensionManifest(string $slug, string $scope): void
    {
        $this->writeTestFile($this->projectDir, 'extensions/'.$slug.'/.manifest', <<<MANIFEST
            EXTENSION_AUTHOR=Aavion
            EXTENSION_SLUG={$slug}
            EXTENSION_NAME=Demo Module
            EXTENSION_VERSION=1.0.0
            EXTENSION_SCOPE={$scope}
            EXTENSION_DEPENDENCIES=[]
            MANIFEST);
    }

    private function insertExtension(string $extensionName, string $path, string $status): void
    {
        $this->connection->insert('extension', [
            'uid' => $this->uuid(),
            'extension_scopes' => json_encode(['module'], JSON_THROW_ON_ERROR),
            'extension_name' => $extensionName,
            'path' => $path,
            'manifest_version' => '1.0.0',
            'installed_version' => null,
            'status' => $status,
            'metadata' => json_encode(['registry_state' => 'available'], JSON_THROW_ON_ERROR),
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

    private function uuid(): string
    {
        return Uuid::v7()->toRfc4122();
    }
}
